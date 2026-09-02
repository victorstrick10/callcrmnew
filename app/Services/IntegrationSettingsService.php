<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class IntegrationSettingsService
{
    public function __construct(private AuditService $audit)
    {
    }

    public function getSettings(string $provider): array
    {
        $row = IntegrationSetting::query()->where('provider', $provider)->first();

        return $row ? $this->decryptDict($row->encrypted_json) : [];
    }

    public function saveSettings(string $provider, array $data, bool $enabled = true): IntegrationSetting
    {
        $row = IntegrationSetting::query()->firstOrNew(['provider' => $provider]);
        $existing = $this->decryptDict($row->encrypted_json);

        foreach ($data as $key => $val) {
            if ($val !== '' && $val !== null) {
                $existing[$key] = $val;
            }
        }

        $row->encrypted_json = $this->encryptDict($existing);
        $row->enabled = $enabled;
        $row->save();

        $this->audit->log("Updated {$provider} settings", 'Integration settings saved.');

        return $row;
    }

    public function masked(?string $value): string
    {
        if (! $value) {
            return 'Not configured';
        }
        if (strlen($value) <= 6) {
            return '••••••';
        }

        return '••••••••'.substr($value, -4);
    }

    public function encryptDict(array $value): string
    {
        return Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    public function decryptDict(?string $value): array
    {
        if (! $value) {
            return [];
        }

        try {
            $raw = Crypt::decryptString($value);
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }
}
