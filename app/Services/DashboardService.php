<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BrowserProfile;

class DashboardService
{
    public function stats(): array
    {
        return [
            'appointments' => Appointment::query()->count(),
            'scheduled' => Appointment::query()->where('status', 'scheduled')->count(),
            'profiles' => BrowserProfile::query()->where('status', 'created')->count(),
            'failed' => BrowserProfile::query()->where('status', 'failed')->count(),
        ];
    }

    public function upcoming(int $limit = 10)
    {
        return Appointment::query()
            ->with('contact')
            ->orderBy('start_time')
            ->limit($limit)
            ->get();
    }

    public function recentLogs(int $limit = 8)
    {
        return AuditLog::query()->orderByDesc('created_at')->limit($limit)->get();
    }
}
