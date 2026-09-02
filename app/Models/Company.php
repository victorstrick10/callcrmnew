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
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

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
