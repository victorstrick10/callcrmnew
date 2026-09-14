<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaticProxy extends Model
{
    protected $fillable = [
        'label', 'provider', 'network_type', 'location', 'host', 'port', 'username', 'password', 'protocol', 'enabled',
        'last_check_status', 'exit_ip', 'exit_country', 'exit_region', 'exit_region_code', 'exit_zip', 'exit_city', 'exit_isp', 'last_checked_at',
    ];

    public function scopeMobile($query)
    {
        return $query->where('network_type', 'mobile');
    }

    protected $casts = [
        'port' => 'integer',
        'enabled' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    /** up | down | unknown for the last liveness check. */
    public function checkState(): string
    {
        return match ($this->last_check_status) {
            'up' => 'up',
            'down' => 'down',
            default => 'unknown',
        };
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Enabled proxies that are safe to suggest when creating browsers: never
     * offer one whose last liveness check was DOWN (untested/up are fine).
     */
    public function scopeUsable($query)
    {
        return $query->where('enabled', true)->where(function ($q) {
            $q->whereNull('last_check_status')->orWhere('last_check_status', '!=', 'down');
        });
    }

    /** True when the last liveness check marked this proxy down. */
    public function isDown(): bool
    {
        return $this->last_check_status === 'down';
    }

    public function toMultiloginProxy(): array
    {
        return [
            'host' => $this->host,
            'port' => (int) $this->port,
            'username' => (string) $this->username,
            'password' => (string) ($this->password ?? ''),
            'protocol' => $this->protocol ?: 'http',
        ];
    }
}
