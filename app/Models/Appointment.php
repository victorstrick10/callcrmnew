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
        'outcome',
        'outcome_note',
        'outcome_at',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'proxy_created_at' => 'datetime',
        'proxy_candidates_json' => 'array',
        'geo_json' => 'array',
        'geo_enriched_at' => 'datetime',
        'outcome_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'proxy_port' => 'integer',
    ];

    /** Call outcome options: key => human label. */
    public const OUTCOMES = [
        'pending' => 'Pending',
        'scheduled' => 'Scheduled',
        'joined' => 'Joined',
        'joined_line' => 'Joined/LINE (deal closed)',
        'joined_vorr' => 'Joined/Vorr',
        'joined_left' => 'Joined/Left Call',
        'no_show' => "Didn't join",
        'rescheduled' => 'Rescheduled',
        'canceled' => 'Canceled',
    ];

    /** The outcome that represents a closed deal. */
    public const OUTCOME_DEAL = 'joined_line';

    /** Outcomes that mean the invitee attended the call. */
    public const OUTCOMES_ATTENDED = ['joined', 'joined_line', 'joined_vorr', 'joined_left'];

    /**
     * The outcome to show by default. When no outcome has been logged yet, fall
     * back to the Calendly call status (scheduled/canceled) so the dropdown is
     * auto-filled from the call's status instead of a bare "Pending".
     */
    public function effectiveOutcome(): string
    {
        if ($this->hasCustomOutcome()) {
            return $this->outcome;
        }

        if (! in_array($this->outcome, ['', 'pending'], true)) {
            return (string) $this->outcome;
        }

        // Auto-fill from the call status: scheduled→Scheduled, canceled→Canceled,
        // rescheduled→Rescheduled (any status that matches an outcome key).
        $status = (string) $this->status;
        if (array_key_exists($status, self::OUTCOMES)) {
            return $status;
        }

        return $status === 'canceled' ? 'canceled' : 'scheduled';
    }

    public function outcomeLabel(): string
    {
        if (isset(self::OUTCOMES[$this->outcome])) {
            return self::OUTCOMES[$this->outcome];
        }

        $value = trim((string) $this->outcome);

        return $value === '' ? 'Pending' : $value;
    }

    /** True when the stored outcome is a custom (typed) value, not a preset. */
    public function hasCustomOutcome(): bool
    {
        $value = trim((string) $this->outcome);

        return $value !== '' && ! array_key_exists($value, self::OUTCOMES);
    }

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

    /** Start time in the operator display timezone (GMT+1 / Europe/Belgrade), matching Calendly. */
    public function localStart(): ?\Illuminate\Support\Carbon
    {
        return $this->inDisplayTz($this->start_time);
    }

    /** End time in the operator display timezone (GMT+1 / Europe/Belgrade). */
    public function localEnd(): ?\Illuminate\Support\Carbon
    {
        return $this->inDisplayTz($this->end_time);
    }

    /**
     * Convert a UTC-stored time to the operator display timezone so all call
     * times are shown in one consistent zone (GMT+1) rather than each invitee's
     * local zone.
     */
    private function inDisplayTz(?\Illuminate\Support\Carbon $value): ?\Illuminate\Support\Carbon
    {
        if (! $value) {
            return null;
        }

        $tz = config('app.display_timezone') ?: config('app.timezone');

        try {
            return $value->copy()->setTimezone($tz);
        } catch (\Throwable) {
            return $value;
        }
    }

    /** Short timezone abbreviation for the display timezone (e.g. CEST). */
    public function inviteeTzAbbr(): string
    {
        $local = $this->localStart();

        return $local ? $local->format('T') : '';
    }
}
