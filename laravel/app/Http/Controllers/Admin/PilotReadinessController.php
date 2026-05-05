<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Operations\PilotReadinessService;
use Illuminate\View\View;

class PilotReadinessController extends Controller
{
    public function __invoke(PilotReadinessService $readiness): View
    {
        return view('admin.pilot-readiness.index', [
            'report' => $readiness->report(),
        ]);
    }
}
