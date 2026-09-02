<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaticProxy extends Model
{
    protected $fillable = [
        'label', 'provider', 'location', 'host', 'port', 'username', 'password', 'protocol', 'enabled',
    ];

    protected $casts = [
        'port' => 'integer',
        'enabled' => 'boolean',
    ];

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
