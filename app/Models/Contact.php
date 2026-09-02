<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $fillable = [
        'company_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'referrer',
        'lead_user_agent',
        'lead_ip',
        'lead_raw_json',
        'lead_synced_at',
    ];

    protected $casts = [
        'lead_raw_json' => 'array',
        'lead_synced_at' => 'datetime',
    ];

    /** Tenant company (not the free-text employer `company` column). */
    public function ownerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function getFullNameAttribute(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : (string) $this->email;
    }
}
