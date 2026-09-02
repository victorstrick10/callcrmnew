<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(): View
    {
        $logs = AuditLog::query()->orderByDesc('created_at')->limit(300)->get();

        return view('logs.index', compact('logs'));
    }
}
