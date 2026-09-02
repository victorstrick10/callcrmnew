<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'lead_api_url',
        'calendly_org_uri',
        'multilogin_base_url',
        'multilogin_config',
        'service_status',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'multilogin_config' => 'array',
        'service_status' => 'array',
    ];

    /** Advanced Multilogin settings for this company (workspace, folders, proxy, etc.). */
    public function multiloginConfig(): array
    {
        return is_array($this->multilogin_config) ? $this->multilogin_config : [];
    }

    /** Record a service connectivity result (lead|calendly|multilogin). */
    public function setServiceStatus(string $service, bool $ok, string $message = ''): void
    {
        $status = is_array($this->service_status) ? $this->service_status : [];
        $status[$service] = [
            'ok' => $ok,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];
        $this->service_status = $status;
        $this->save();
    }

    /** up | down | unknown for a given service. */
    public function serviceState(string $service): string
    {
        $status = is_array($this->service_status) ? ($this->service_status[$service] ?? null) : null;
        if (! is_array($status) || ! array_key_exists('ok', $status)) {
            return 'unknown';
        }

        return $status['ok'] ? 'up' : 'down';
    }

    public function serviceMessage(string $service): string
    {
        $status = is_array($this->service_status) ? ($this->service_status[$service] ?? null) : null;

        return is_array($status) ? (string) ($status['message'] ?? '') : '';
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function setLeadApiKey(?string $value): void
    {
        $this->lead_api_key_encrypted = $value ? Crypt::encryptString($value) : null;
    }

    public function getLeadApiKey(): ?string
    {
        return $this->decryptNullable($this->lead_api_key_encrypted);
    }

    public function setCalendlyApiToken(?string $value): void
    {
        $this->calendly_api_token_encrypted = $value ? Crypt::encryptString($value) : null;
    }

    public function getCalendlyApiToken(): ?string
    {
        return $this->decryptNullable($this->calendly_api_token_encrypted);
    }

    public function setCalendlyWebhookSigningKey(?string $value): void
    {
        $this->calendly_webhook_signing_key_encrypted = $value ? Crypt::encryptString($value) : null;
    }

    public function getCalendlyWebhookSigningKey(): ?string
    {
        return $this->decryptNullable($this->calendly_webhook_signing_key_encrypted);
    }

    public function setMultiloginToken(?string $value): void
    {
        $this->multilogin_token_encrypted = $value ? Crypt::encryptString($value) : null;
    }

    public function getMultiloginToken(): ?string
    {
        return $this->decryptNullable($this->multilogin_token_encrypted);
    }

    private function decryptNullable(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
