<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DirectRunnerCommandAckRequest;
use App\Http\Requests\DirectRunnerCommandPollRequest;
use App\Services\Runner\DirectRunnerCommandAckService;
use App\Services\Runner\DirectRunnerCommandPollService;
use Illuminate\Http\JsonResponse;

class DirectRunnerCommandController extends Controller
{
    public function __construct(
        private readonly DirectRunnerCommandPollService $poller,
        private readonly DirectRunnerCommandAckService $acks,
    ) {
    }

    public function poll(DirectRunnerCommandPollRequest $request): JsonResponse
    {
        return response()->json($this->poller->poll($request, $request->validated()));
    }

    public function acknowledge(DirectRunnerCommandAckRequest $request): JsonResponse
    {
        return response()->json($this->acks->acknowledge($request, $request->validated()));
    }
}
