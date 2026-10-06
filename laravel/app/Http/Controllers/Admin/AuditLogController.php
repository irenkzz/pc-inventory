<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.audit-log.index', [
            'entries' => AuditLog::query()->orderByDesc('id')->paginate(50),
        ]);
    }
}
