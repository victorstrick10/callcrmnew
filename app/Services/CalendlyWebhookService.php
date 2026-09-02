<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CalendlyWebhookService
{
    public function __construct(private AuditService $audit)
    {
    }

    public function handle(array $payload, Request $request, ?Company $company = null): array
    {
        $company = $company ?: Company::query()->where('slug', 'default')->first();
        if (! $company) {
            return ['ok' => false, 'error' => 'No company configured', 'status' => 500];
        }

        $eventType = $payload['event'] ?? '';
        $data = $payload['payload'] ?? $payload;

        $invitee = $data['invitee'] ?? $data;
        $email = $invitee['email'] ?? $data['email'] ?? null;
        $name = $invitee['name'] ?? $data['name'] ?? 'Calendly Client';

        if (! $email) {
            return ['ok' => false, 'error' => 'Missing invitee email', 'status' => 400];
        }

        $parts = preg_split('/\s+/', trim($name), 2);
        $first = $parts[0] ?? '';
        $last = $parts[1] ?? '';

        $contact = Contact::query()
            ->where('company_id', $company->id)
            ->where('email', strtolower($email))
            ->first();

        if (! $contact) {
            $contact = Contact::create([
                'company_id' => $company->id,
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower($email),
            ]);
        }

        $eventUri = $data['uri']
            ?? $data['event_uri']
            ?? ($data['scheduled_event']['uri'] ?? null)
            ?? $payload['id']
            ?? null;

        $existing = $eventUri
            ? Appointment::query()->where('calendly_event_uri', $eventUri)->first()
            : null;

        if (str_ends_with((string) $eventType, 'canceled') && $existing) {
            $existing->status = 'canceled';
            $existing->company_id = $company->id;
            $existing->save();
            $this->audit->log('Calendly appointment canceled', "Appointment #{$existing->id} ({$company->slug})");

            return ['ok' => true, 'status' => 'canceled', 'http' => 200];
        }

        if (! $existing) {
            $scheduledEvent = $data['scheduled_event'] ?? [];
            $start = $scheduledEvent['start_time'] ?? $data['start_time'] ?? null;
            $end = $scheduledEvent['end_time'] ?? $data['end_time'] ?? null;

            $existing = Appointment::create([
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'calendly_event_uri' => $eventUri,
                'calendly_invitee_uri' => $invitee['uri'] ?? '',
                'event_name' => $scheduledEvent['name'] ?? $data['event_name'] ?? 'Scheduled Call',
                'start_time' => $this->parseDt($start),
                'end_time' => $this->parseDt($end),
                'invitee_timezone' => $invitee['timezone'] ?? $data['timezone'] ?? '',
                'status' => 'scheduled',
                'ip_address' => explode(',', $request->header('X-Forwarded-For', $request->ip() ?? ''))[0],
                'user_agent' => $request->userAgent() ?? '',
            ]);

            $this->audit->log(
                'Calendly appointment received',
                "{$contact->full_name} / Appointment #{$existing->id} ({$company->slug})"
            );
        }

        return ['ok' => true, 'appointment_id' => $existing->id, 'http' => 200];
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
