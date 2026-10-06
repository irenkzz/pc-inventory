<?php

namespace App\Console\Commands;

use App\Models\Collector;
use App\Models\Runner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckStale extends Command
{
    protected $signature = 'inventory:check-stale {--hours= : Hours without contact (default inventory.stale_after_hours)} {--fail-on-stale : Exit 1 when anything is stale}';

    protected $description = 'List runners and collectors not seen recently; optionally post a webhook alert.';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('inventory.stale_after_hours', 24));
        $cutoff = now()->subHours($hours);
        $stale = fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff);

        $runners = Runner::query()->where($stale)->orderBy('last_seen_at')->get(['runner_id', 'hostname', 'site_id', 'last_seen_at']);
        $collectors = Collector::query()->where($stale)->orderBy('last_seen_at')->get(['site_id', 'collector_name', 'last_seen_at']);

        $this->info("Stale threshold: {$hours}h. Stale runners: {$runners->count()}, stale collectors: {$collectors->count()}.");
        $rows = $runners->map(fn ($r) => ['runner', $r->runner_id, $r->hostname, $r->site_id, $r->last_seen_at?->toDateTimeString() ?? 'never'])
            ->concat($collectors->map(fn ($c) => ['collector', $c->collector_name, '', $c->site_id, $c->last_seen_at?->toDateTimeString() ?? 'never']));
        if ($rows->isNotEmpty()) {
            $this->table(['Type', 'Id', 'Hostname', 'Site', 'Last seen'], $rows->all());
            $this->sendAlert($runners, $collectors, $hours);
        }

        return $this->option('fail-on-stale') && $rows->isNotEmpty() ? self::FAILURE : self::SUCCESS;
    }

    private function sendAlert($runners, $collectors, int $hours): void
    {
        $url = config('inventory.alert_webhook_url');
        if (! $url) {
            return;
        }

        $key = 'inventory:stale-alert:' . md5($runners->pluck('runner_id')->sort()->implode('|') . '#' . $collectors->map(fn ($c) => $c->site_id . '/' . $c->collector_name)->sort()->implode('|'));
        // Cache::add is atomic: only the first caller within the cooldown gets to send.
        if (! Cache::add($key, true, now()->addHours((int) config('inventory.alert_cooldown_hours', 12)))) {
            $this->line('Alert suppressed (cooldown).');

            return;
        }

        try {
            Http::timeout(5)->post($url, [
                'stale_after_hours' => $hours,
                'stale_runner_count' => $runners->count(),
                'stale_collector_count' => $collectors->count(),
                'runners' => $runners->take(20)->map(fn ($r) => ['runner_id' => $r->runner_id, 'hostname' => $r->hostname])->values()->all(),
            ])->throw();
        } catch (\Throwable $e) {
            Cache::forget($key); // let the next run retry
            Log::warning('inventory:check-stale webhook failed: ' . $e->getMessage());
        }
    }
}
