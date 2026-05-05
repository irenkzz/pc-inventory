<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (\Throwable) {
            $database = 'error';
        }

        $status = $database === 'ok' ? 200 : 503;

        return response()->json([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'framework' => app()->version(),
            'database' => $database,
            'time' => now()->toIso8601String(),
        ], $status);
    }
}
