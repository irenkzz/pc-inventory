<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Runner;
use App\Support\InventoryTime;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    // ponytail: exports everything (no list-page filters); add filters when someone needs them.
    public function devices(): StreamedResponse
    {
        return $this->stream('inventory-devices.csv', [
            'asset_code', 'site', 'department', 'user_name', 'location', 'room', 'manufacturer',
            'model', 'system_type', 'serial_no', 'status', 'first_seen_at', 'last_seen_at',
        ], Device::query()->orderBy('current_asset_code')->orderBy('id'), fn (Device $d) => [
            $d->current_asset_code, $d->current_site, $d->current_department, $d->current_user_name,
            $d->current_location, $d->current_room, $d->manufacturer, $d->model, $d->system_type,
            $d->serial_no, $d->status, InventoryTime::format($d->first_seen_at), InventoryTime::format($d->last_seen_at),
        ]);
    }

    public function runners(): StreamedResponse
    {
        return $this->stream('inventory-runners.csv', [
            'runner_id', 'hostname', 'site_id', 'runner_version', 'install_mode', 'last_seen_at',
            'last_successful_inventory_at', 'last_inventory_status', 'last_upload_status',
        ], Runner::query()->orderBy('runner_id')->orderBy('id'), fn (Runner $r) => [
            $r->runner_id, $r->hostname, $r->site_id, $r->runner_version, $r->install_mode,
            InventoryTime::format($r->last_seen_at), InventoryTime::format($r->last_successful_inventory_at),
            $r->last_inventory_status, $r->last_upload_status,
        ]);
    }

    private function stream(string $filename, array $headers, Builder $query, callable $row): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $query, $row): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($query->lazy(1000) as $model) {
                fputcsv($out, array_map([$this, 'safe'], $row($model)));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formula injection. */
    private function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
    }
}
