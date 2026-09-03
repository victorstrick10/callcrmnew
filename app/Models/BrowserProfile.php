<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrowserProfile extends Model
{
    protected $fillable = [
        'appointment_id',
        'number',
        'profile_role',
        'profile_name',
        'multilogin_profile_id',
        'proxy_label',
        'status',
        'error_message',
        'is_kept',
    ];

    protected $casts = [
        'is_kept' => 'boolean',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
