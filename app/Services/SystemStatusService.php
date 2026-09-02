<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\IntegrationSetting;
use Illuminate\Support\Carbon;

/**
 * Aggregates live health/status of the automation pipeline: integration
 * connectivity (IPinfo / Multilogin), Calendly coverage across companies,
 * and the last automatic sync run. Shared by the sidebar footer and the
 * dashboard so both always agree.
 */
class SystemStatusService
{
    private ?array $memo = null;

    public const SYNC_CADENCE = 'every 15 min';

    public function snapshot(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $rows = IntegrationSetting::query()->get()->keyBy('provider');

        $ipinfo = $this->providerStatus($rows->get('ipinfo'), 'api_token');
        $multilogin = $this->providerStatus($rows->get('multilogin'), 'automation_token');

        $companiesTotal = Company::query()->count();
        $calendlyCompanies = Company::query()
            ->whereNotNull('calendly_api_token_encrypted')
            ->where('calendly_api_token_encrypted', '!=', '')
            ->count();

        $lastSync = AuditLog::query()
            ->where(function ($q) {
                $q->where('action', 'like', '%sync completed%')
                    ->orWhere('action', 'like', '%enrichment on intake%')
                    ->orWhere('action', 'like', '%appointment received%');
            })
            ->orderByDesc('created_at')
            ->value('created_at');

        $lastSyncAt = $lastSync ? Carbon::parse($lastSync) : null;

        $this->memo = [
            'ipinfo' => $ipinfo,
            'multilogin' => $multilogin,
            'calendly' => [
                'configured' => $calendlyCompanies > 0,
                'companies' => $calendlyCompanies,
                'companies_total' => $companiesTotal,
            ],
            'auto_sync' => self::SYNC_CADENCE,
            'last_sync_at' => $lastSyncAt,
            'last_sync_human' => $lastSyncAt ? $lastSyncAt->diffForHumans() : 'no runs yet',
            'healthy' => $ipinfo['ok'] && $multilogin['ok'],
        ];

        return $this->memo;
    }

    /**
     * @return array{configured:bool,ok:bool,state:string,message:string,checked_at:?Carbon}
     */
    private function providerStatus(?IntegrationSetting $row, string $tokenKey): array
    {
        $settings = $row ? app(IntegrationSettingsService::class)->decryptDict($row->encrypted_json) : [];
        $configured = trim((string) ($settings[$tokenKey] ?? '')) !== '';
        $status = (string) ($row->last_test_status ?? '');

        $state = match (true) {
            $status === 'success' => 'up',
            $status === 'error' => 'down',
            $configured => 'unknown',
            default => 'missing',
        };

        return [
            'configured' => $configured,
            'ok' => $state === 'up',
            'state' => $state,
            'message' => (string) ($row->last_test_message ?? ''),
            'checked_at' => $row?->updated_at ? Carbon::parse($row->updated_at) : null,
        ];
    }
}
