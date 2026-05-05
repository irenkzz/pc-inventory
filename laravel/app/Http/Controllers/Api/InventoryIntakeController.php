<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryIntakeController extends Controller
{
    public function __construct(private readonly InventoryIngestService $ingest)
    {
    }

    public function json(Request $request): JsonResponse
    {
        $this->checkCentralToken($request);

        $payload = $request->json()->all();
        abort_unless(is_array($payload), 400);

        return response()->json($this->ingest->ingestJsonPayload($payload));
    }

    public function csv(Request $request): JsonResponse
    {
        $this->checkCentralToken($request);

        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');
        $text = file_get_contents($file->getRealPath());

        return response()->json($this->ingest->ingestCsvText(
            (string) $text,
            $file->getClientOriginalName() ?: 'scan.csv',
            'api_csv',
        ));
    }

    private function checkCentralToken(Request $request): void
    {
        $expected = trim((string) config('inventory.central_intake_token', ''));
        if ($expected === '') {
            return;
        }

        $actual = trim(str_replace('Bearer ', '', (string) $request->header('Authorization', '')));
        abort_if($actual !== $expected, 401);
    }
}
