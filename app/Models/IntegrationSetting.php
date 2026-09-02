<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $fillable = [
        'provider',
        'encrypted_json',
        'enabled',
        'last_test_status',
        'last_test_message',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
