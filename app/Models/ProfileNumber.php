<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileNumber extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'number',
        'status',
        'appointment_id',
        'profile_type',
        'multilogin_profile_id',
        'profile_name',
        'reserved_at',
        'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'number' => 'integer',
        'reserved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
