<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChangeController extends Controller
{
    public function index(Request $request): View
    {
        $severity = trim((string) $request->query('severity', ''));
        $site = trim((string) $request->query('site', ''));

        return view('admin.changes.index', [
            'changes' => ChangeLog::query()
                ->with('device')
                ->when($severity !== '', fn ($q) => $q->where('severity', $severity))
                ->when($site !== '', fn ($q) => $q->where('observed_site', $site))
                ->orderByDesc('observed_at')
                ->paginate(50)
                ->withQueryString(),
            'severity' => $severity,
            'site' => $site,
        ]);
    }
}
