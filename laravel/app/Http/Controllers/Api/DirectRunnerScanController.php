<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DirectRunnerScanUploadRequest;
use App\Services\Runner\DirectRunnerScanUploadService;
use Illuminate\Http\JsonResponse;

class DirectRunnerScanController extends Controller
{
    public function __construct(private readonly DirectRunnerScanUploadService $uploads)
    {
    }

    public function store(DirectRunnerScanUploadRequest $request): JsonResponse
    {
        return response()->json($this->uploads->ingest($request));
    }
}
