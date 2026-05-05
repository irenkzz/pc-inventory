<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeLog;
use Illuminate\View\View;

class ChangeController extends Controller
{
    public function index(): View
    {
        return view('admin.changes.index', [
            'changes' => ChangeLog::query()->with('device')->orderByDesc('observed_at')->limit(500)->get(),
        ]);
    }
}
