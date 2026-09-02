<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    protected $fillable = [
        'company_id',
        'contact_id',
        'calendly_event_uri',
        'calendly_invitee_uri',
        'event_name',
        'start_time',
        'end_time',
        'invitee_timezone',
        'status',
        'ip_address',
        'user_agent',
        'city',
        'region',
        'country',
        'country_code',
        'latitude',
        'longitude',
        'timezone',
        'proxy_status',
        'proxy_host',
        'proxy_port',
        'proxy_username',
        'proxy_password',
        'proxy_protocol',
        'proxy_country',
        'proxy_region',
        'proxy_city',
        'proxy_requested_region',
        'proxy_requested_city',
        'proxy_match_level',
        'proxy_raw_response',
        'proxy_created_at',
        'proxy_last_error',
        'client_isp',
        'client_org',
        'client_asn',
        'proxy_exit_ip',
        'proxy_isp',
        'proxy_org',
        'proxy_asn',
        'proxy_actual_country',
        'proxy_actual_region',
        'proxy_actual_city',
        'proxy_candidates_json',
        'geo_json',
        'geo_enriched_at',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'proxy_created_at' => 'datetime',
        'proxy_candidates_json' => 'array',
        'geo_json' => 'array',
        'geo_enriched_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'proxy_port' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(BrowserProfile::class);
    }

    /** Start time converted to the invitee's Calendly timezone for display. */
    public function localStart(): ?\Illuminate\Support\Carbon
    {
        return $this->inInviteeTz($this->start_time);
    }

    /** End time converted to the invitee's Calendly timezone for display. */
    public function localEnd(): ?\Illuminate\Support\Carbon
    {
        return $this->inInviteeTz($this->end_time);
    }

    private function inInviteeTz(?\Illuminate\Support\Carbon $value): ?\Illuminate\Support\Carbon
    {
        if (! $value) {
            return null;
        }

        $tz = trim((string) $this->invitee_timezone);
        if ($tz === '') {
            return $value;
        }

        try {
            return $value->copy()->setTimezone($tz);
        } catch (\Throwable) {
            return $value;
        }
    }

    /** Short timezone abbreviation for the invitee (e.g. CEST), for display. */
    public function inviteeTzAbbr(): string
    {
        $local = $this->localStart();

        return $local ? $local->format('T') : '';
    }
}
