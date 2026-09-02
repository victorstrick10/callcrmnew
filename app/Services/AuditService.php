<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditService
{
    public function log(string $action, string $details = ''): void
    {
        AuditLog::create([
            'action' => $action,
            'details' => $details,
            'created_at' => now(),
        ]);
    }
}
