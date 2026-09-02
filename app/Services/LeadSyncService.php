<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Support\Str;

class LeadSyncService
{
    public function __construct(
        private CompanyLeadApiClient $client,
        private AuditService $audit,
    ) {
    }

    /**
     * @return array{created:int,updated:int,skipped:int}
     */
    public function syncCompany(Company $company): array
    {
        $rows = $this->client->fetchAll($company);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;
                continue;
            }

            $result = $this->upsertLead($company, $row);
            if ($result === 'skipped') {
                $skipped++;
            } elseif ($result === 'created') {
                $created++;
            } else {
                $updated++;
            }
        }

        $this->audit->log(
            'Lead sync completed',
            "Company {$company->slug}: created={$created} updated={$updated} skipped={$skipped}"
        );

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @return array{created:int,updated:int,skipped:int}
     */
    public function syncEmail(Company $company, string $email): array
    {
        $row = $this->client->fetchByEmail($company, $email);
        if (! $row) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1];
        }

        $result = $this->upsertLead($company, $row);

        return [
            'created' => $result === 'created' ? 1 : 0,
            'updated' => $result === 'updated' ? 1 : 0,
            'skipped' => $result === 'skipped' ? 1 : 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return 'created'|'updated'|'skipped'
     */
    public function upsertLead(Company $company, array $row): string
    {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'skipped';
        }

        $fullName = trim((string) ($row['full_name'] ?? ''));
        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        if ($first === '' && $fullName !== '') {
            $first = (string) Str::before($fullName, ' ');
            $last = trim((string) Str::after($fullName, ' '));
        }

        $contact = Contact::query()->firstOrNew([
            'company_id' => $company->id,
            'email' => $email,
        ]);

        $isNew = ! $contact->exists;
        $contact->fill([
            'first_name' => $first,
            'last_name' => $last,
            'referrer' => (string) ($row['referrer'] ?? ''),
            'lead_user_agent' => (string) ($row['user_agent'] ?? ''),
            'lead_ip' => (string) ($row['ip'] ?? $row['ip_address'] ?? ''),
            'lead_raw_json' => $row,
            'lead_synced_at' => now(),
        ]);
        $contact->save();

        return $isNew ? 'created' : 'updated';
    }
}
