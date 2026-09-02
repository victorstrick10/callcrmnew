<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaticProxy extends Model
{
    protected $fillable = [
        'label', 'provider', 'network_type', 'location', 'host', 'port', 'username', 'password', 'protocol', 'enabled',
        'last_check_status', 'exit_ip', 'last_checked_at',
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
