<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DirectRunnerHeartbeatRequest;
use App\Services\Runner\DirectRunnerHeartbeatService;
use App\Services\Security\DirectRunnerAuthService;
use Illuminate\Http\JsonResponse;

class DirectRunnerHeartbeatController extends Controller
{
    public function __construct(
        private readonly DirectRunnerAuthService $auth,
        private readonly DirectRunnerHeartbeatService $heartbeats,
    ) {
    }

    public function store(DirectRunnerHeartbeatRequest $request): JsonResponse
    {
        $context = $this->auth->authenticate($request);
        $runner = $this->heartbeats->upsert($request->validated(), $context);

        return response()->json([
            'status' => 'ok',
            'site_code' => $context['site_code'],
            'runner_id' => $runner->runner_id,
        ]);
    }
}
