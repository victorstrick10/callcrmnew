<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

class AppointmentSyncService
{
    public function __construct(
        private CalendlyApiClient $calendly,
        private AuditService $audit,
    ) {
    }

    /**
     * Sync all Calendly invitees for a company:
     * - match existing API leads by email → update + attach call times
     * - otherwise create a minimal lead (name/email only, no IP/UA) + call times
     *
     * @return array{created:int,updated:int,skipped:int,events:int,leads_created:int,leads_matched:int}
     */
    public function syncCompany(Company $company): array
    {
        @set_time_limit(600);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $leadsCreated = 0;
        $matchedEmails = [];
        $createdEmails = [];

        /** @var Collection<string, Contact> $contactsByEmail */
        $contactsByEmail = Contact::query()
            ->where('company_id', $company->id)
            ->get()
            ->keyBy(fn (Contact $c) => strtolower((string) $c->email));

        // Calendly expects UTC Zulu timestamps.
        // Near-term first so today's/upcoming calls persist even if a long backfill is interrupted.
        $nearMin = now()->subDay()->utc()->format('Y-m-d\TH:i:s.000000\Z');
        $nearMax = now()->addDays(30)->utc()->format('Y-m-d\TH:i:s.000000\Z');
        $historyMin = now()->subMonths(6)->utc()->format('Y-m-d\TH:i:s.000000\Z');

        $processedUris = [];
        $allEventUris = [];

        foreach ([
            ['min_start_time' => $nearMin, 'max_start_time' => $nearMax],
            ['min_start_time' => $historyMin],
        ] as $window) {
            $active = $this->safeListEvents($company, 'active', $window['min_start_time'], null, $window);
            $canceled = $this->safeListEvents($company, 'canceled', $window['min_start_time'], null, $window);

            $batchUris = [];
            $eventsByUri = [];
            foreach (array_merge($active, $canceled) as $event) {
                $uri = $event['uri'] ?? null;
                if (! $uri || isset($processedUris[$uri])) {
                    continue;
                }
                $eventsByUri[$uri] = $event;
                $batchUris[] = $uri;
                $allEventUris[$uri] = true;
            }

            foreach (array_chunk($batchUris, 25) as $chunk) {
                foreach ($chunk as $eventUri) {
                    $event = $eventsByUri[$eventUri];
                    $processedUris[$eventUri] = true;

                    try {
                        $invitees = $this->calendly->listEventInvitees($company, $eventUri);
                    } catch (Throwable) {
                        $skipped++;
                        continue;
                    }

                    foreach ($invitees as $invitee) {
                        $email = strtolower(trim((string) ($invitee['email'] ?? '')));
                        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $skipped++;
                            continue;
                        }

                        $existedBefore = $contactsByEmail->has($email);
                        [$contact, $leadAction] = $this->resolveContactFromInvitee(
                            $company,
                            $contactsByEmail,
                            $invitee,
                            $email
                        );

                        if ($leadAction === 'created' && ! isset($createdEmails[$email])) {
                            $createdEmails[$email] = true;
                            $leadsCreated++;
                        } elseif ($existedBefore && ! isset($matchedEmails[$email]) && ! isset($createdEmails[$email])) {
                            $matchedEmails[$email] = true;
                        }

                        $result = $this->upsertEventAppointment($company, $contact, $event, $invitee);
                        if ($result === 'created') {
                            $created++;
                        } elseif ($result === 'updated') {
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    }

                    usleep(100000);
                }

                usleep(250000);
            }
        }

        $leadsMatched = count($matchedEmails);

        $this->audit->log(
            'Calendly appointment sync completed',
            "Company {$company->slug}: events=".count($allEventUris)
            ." appt_c={$created} appt_u={$updated} leads_c={$leadsCreated} leads_m={$leadsMatched} skipped={$skipped}"
        );

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'events' => count($allEventUris),
            'leads_created' => $leadsCreated,
            'leads_matched' => $leadsMatched,
        ];
    }

    /**
     * @return array{created:int,updated:int,skipped:int}
     */
    public function syncContact(Company $company, Contact $contact): array
    {
        @set_time_limit(300);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        $minStart = now()->subMonths(6)->utc()->format('Y-m-d\TH:i:s.000000\Z');

        $active = $this->safeListEvents($company, 'active', $minStart, $contact->email);
        $canceled = $this->safeListEvents($company, 'canceled', $minStart, $contact->email);

        $eventsByUri = [];
        foreach (array_merge($active, $canceled) as $event) {
            $uri = $event['uri'] ?? null;
            if ($uri) {
                $eventsByUri[$uri] = $event;
            }
        }

        foreach ($eventsByUri as $event) {
            $result = $this->upsertEventAppointment($company, $contact, $event);
            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            } else {
                $skipped++;
            }
        }

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @param  Collection<string, Contact>  $contactsByEmail
     * @param  array<string,mixed>  $invitee
     * @return array{0:Contact,1:'created'|'matched'|'existing'}
     */
    private function resolveContactFromInvitee(
        Company $company,
        Collection $contactsByEmail,
        array $invitee,
        string $email
    ): array {
        if ($contactsByEmail->has($email)) {
            /** @var Contact $contact */
            $contact = $contactsByEmail->get($email);

            // Light name refresh if API lead had empty names and Calendly has them.
            $this->maybeFillNamesFromInvitee($contact, $invitee);

            return [$contact, 'matched'];
        }

        [$first, $last] = $this->splitInviteeName($invitee);

        $contact = Contact::create([
            'company_id' => $company->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => '',
            'company' => '',
            'referrer' => '',
            'lead_user_agent' => null,
            'lead_ip' => '',
            'lead_raw_json' => [
                'source' => 'calendly',
                'invitee' => [
                    'uri' => $invitee['uri'] ?? null,
                    'email' => $email,
                    'name' => $invitee['name'] ?? null,
                    'timezone' => $invitee['timezone'] ?? null,
                ],
            ],
            'lead_synced_at' => now(),
        ]);

        $contactsByEmail->put($email, $contact);

        return [$contact, 'created'];
    }

    /**
     * @param  array<string,mixed>  $invitee
     */
    private function maybeFillNamesFromInvitee(Contact $contact, array $invitee): void
    {
        if (trim((string) $contact->first_name) !== '' || trim((string) $contact->last_name) !== '') {
            return;
        }

        [$first, $last] = $this->splitInviteeName($invitee);
        if ($first === '' && $last === '') {
            return;
        }

        $contact->first_name = $first;
        $contact->last_name = $last;
        $contact->save();
    }

    /**
     * @param  array<string,mixed>  $invitee
     * @return array{0:string,1:string}
     */
    private function splitInviteeName(array $invitee): array
    {
        $name = trim((string) ($invitee['name'] ?? ''));
        if ($name === '') {
            $first = trim((string) ($invitee['first_name'] ?? ''));
            $last = trim((string) ($invitee['last_name'] ?? ''));

            return [$first, $last];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [];

        return [
            (string) ($parts[0] ?? ''),
            (string) ($parts[1] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,mixed>|null  $invitee
     * @return 'created'|'updated'|'skipped'
     */
    public function upsertEventAppointment(
        Company $company,
        Contact $contact,
        array $event,
        ?array $invitee = null
    ): string {
        $eventUri = $event['uri'] ?? null;
        if (! $eventUri) {
            return 'skipped';
        }

        if ($invitee === null) {
            try {
                $invitees = $this->calendly->listEventInvitees($company, $eventUri, $contact->email);
                $invitee = $invitees[0] ?? null;
            } catch (Throwable) {
                $invitee = null;
            }
        }

        $appointment = Appointment::query()->firstOrNew([
            'calendly_event_uri' => $eventUri,
        ]);

        $isNew = ! $appointment->exists;
        $status = (($event['status'] ?? '') === 'canceled') ? 'canceled' : 'scheduled';

        $appointment->fill([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'calendly_invitee_uri' => $invitee['uri'] ?? ($appointment->calendly_invitee_uri ?? ''),
            'event_name' => $event['name'] ?? 'Scheduled Call',
            'start_time' => $this->parseDt($event['start_time'] ?? null),
            'end_time' => $this->parseDt($event['end_time'] ?? null),
            'invitee_timezone' => $invitee['timezone'] ?? ($appointment->invitee_timezone ?? ''),
            'status' => $status,
        ]);

        if ($isNew) {
            $appointment->ip_address = $contact->lead_ip ?: ($appointment->ip_address ?? '');
            $appointment->user_agent = $contact->lead_user_agent ?: ($appointment->user_agent ?? '');
        }

        $appointment->save();

        return $isNew ? 'created' : 'updated';
    }

    /**
     * @param  array<string,mixed>  $extraQuery
     * @return list<array<string,mixed>>
     */
    private function safeListEvents(
        Company $company,
        string $status,
        string $minStart,
        ?string $inviteeEmail = null,
        array $extraQuery = []
    ): array {
        $query = array_merge(['min_start_time' => $minStart], $extraQuery);

        try {
            return $this->calendly->listScheduledEvents($company, $inviteeEmail, $status, $query);
        } catch (Throwable $e) {
            // Fallback without time filters if Calendly rejects the filter/pagination combo.
            try {
                return $this->calendly->listScheduledEvents($company, $inviteeEmail, $status);
            } catch (Throwable) {
                $this->audit->log(
                    'Calendly event list failed',
                    "Company {$company->slug} status={$status}: ".$e->getMessage()
                );

                return [];
            }
        }
    }

    private function parseDt(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->timezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
