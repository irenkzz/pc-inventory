<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collector;
use App\Models\CollectorSite;
use App\Models\Runner;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SetupWizardController extends Controller
{
    public function __invoke(): View
    {
        $appUrl = trim((string) config('app.url', ''));
        $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $appScheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));

        return view('admin.setup-wizard.index', [
            'appUrlHost' => $appHost !== '' ? $appHost : 'not configured',
            'httpsReady' => $appScheme === 'https',
            'usesPilotOrInternalHost' => $this->usesPilotOrInternalHost($appHost),
            'counts' => $this->safeCounts(),
        ]);
    }

    private function safeCounts(): array
    {
        $hasRunners = Schema::hasTable('runners');

        return [
            'collector_sites' => Schema::hasTable('collector_sites') ? CollectorSite::query()->count() : null,
            'runners' => $hasRunners ? Runner::query()->count() : null,
            'collectors' => Schema::hasTable('collectors') ? Collector::query()->count() : null,
            'direct_runners' => $hasRunners ? Runner::query()->where('transport_mode', 'direct_https')->count() : null,
            'collector_share_runners' => $hasRunners
                ? Runner::query()
                    ->where(fn ($query) => $query->whereNull('transport_mode')->orWhere('transport_mode', '!=', 'direct_https'))
                    ->count()
                : null,
        ];
    }

    private function usesPilotOrInternalHost(string $host): bool
    {
        return str_contains($host, 'inventory-pilot')
            || str_contains($host, '.internal.')
            || str_ends_with($host, '.lan');
    }
}
