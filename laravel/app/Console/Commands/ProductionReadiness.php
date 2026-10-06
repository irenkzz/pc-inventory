<?php

namespace App\Console\Commands;

use App\Models\Collector;
use App\Models\Runner;
use App\Services\Security\SiteTokenStore;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ProductionReadiness extends Command
{
    private const EXPECTED_DIRECT_RUNNER_VERSION = '1.0.22';

    protected $signature = 'inventory:production-readiness';

    protected $description = 'Read-only production and cutover readiness risk checklist';

    private bool $hasFail = false;

    private bool $hasWarn = false;

    /** @var list<string> */
    private array $blockers = [];

    public function handle(SiteTokenStore $siteTokens): int
    {
        $this->hasFail = false;
        $this->hasWarn = false;
        $this->blockers = [];

        $this->line('Inventory production readiness checklist');
        $this->line('[INFO] This command distinguishes pilot readiness, Direct HTTPS small-site readiness, and production/cutover readiness.');
        $this->line('[INFO] Pilot readiness: validates current pilot operating risks only.');
        $this->line('[INFO] Direct HTTPS small-site readiness: validates the second transport for small/no-IT sites first.');
        $this->line('[INFO] Production/cutover readiness: summarizes blockers and caveats that still need separate review.');
        $this->line('[INFO] Passing checks here is not a production approval or cutover approval.');

        $this->environmentSection();
        $databaseOk = $this->databaseSection();
        $this->httpsProxySection();
        $this->storageEvidenceSection();
        $this->securitySecretsSection($siteTokens);
        $this->operationalCommandsSection();
        $this->directHttpsReadinessSection($databaseOk);
        $this->collectorShareReadinessSection($databaseOk);
        $this->cutoverBlockersSection();
        $this->resultSection();

        return $this->hasFail ? self::FAILURE : self::SUCCESS;
    }

    private function environmentSection(): void
    {
        $this->section('Environment');

        $this->infoLine('Laravel base path: ' . base_path());
        $this->infoLine('APP_URL: ' . $this->displayValue(config('app.url')));
        $this->infoLine('APP_ENV: ' . $this->displayValue(config('app.env')));
        $this->infoLine('APP_DEBUG: ' . (config('app.debug') ? 'true' : 'false'));
        $this->infoLine('Configured timezone: ' . $this->displayValue(config('app.timezone')));
        $this->infoLine('Current app time: ' . now()->toDateTimeString());
        $this->infoLine('Storage path: ' . storage_path());
        $this->infoLine('Downloads path: ' . $this->downloadsPath());
        $this->infoLine('Raw archive path: ' . $this->rawArchivePath());
        $this->infoLine('Backup path: ' . $this->backupPath());

        $this->checkAppUrlConfigured();
        $this->checkAppUrlHttps();
        $this->checkAppEnv();
        $this->checkDebugStatus();
        $this->checkTimezone();
        $this->checkPath('Storage path', storage_path(), failWhenBad: true);
    }

    private function databaseSection(): bool
    {
        $this->section('Database');

        $connectionName = (string) config('database.default');
        $driver = (string) config("database.connections.{$connectionName}.driver", $connectionName ?: 'unknown');
        $databaseReachable = false;
        $driverPrinted = false;

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $this->infoLine('Connection driver: ' . $driver);
            $driverPrinted = true;
            $connection->select('select 1');
            $databaseReachable = true;
            $this->ok('Database reachable: yes');
        } catch (\Throwable) {
            if (! $driverPrinted) {
                $this->infoLine('Connection driver: ' . $this->displayValue($driver ?: config('database.default')));
            }
            $this->fail('Database reachable: no');
        }

        $migrationsReachable = false;
        if ($databaseReachable) {
            try {
                $migrationsReachable = Schema::hasTable('migrations');
                $migrationsReachable
                    ? $this->ok('Migrations table reachable: yes')
                    : $this->fail('Migrations table reachable: no');
            } catch (\Throwable) {
                $this->fail('Migrations table reachable: no');
            }
        } else {
            $this->fail('Migrations table reachable: no');
        }

        if ($databaseReachable && $migrationsReachable) {
            $this->checkPendingMigrations();
        }

        $this->checkSqlitePolicy($driver);

        return $databaseReachable && $migrationsReachable;
    }

    private function httpsProxySection(): void
    {
        $this->section('HTTPS / Proxy');

        $appUrl = trim((string) config('app.url', ''));
        $isProduction = config('app.env') === 'production';

        $this->checkAppUrlConfigured();
        $this->checkAppUrlHttps();

        if ($isProduction && $this->isLocalOrPlaceholderUrl($appUrl)) {
            $this->fail('APP_URL is localhost, loopback, or placeholder in production');
        } elseif ($this->isLocalOrPlaceholderUrl($appUrl)) {
            $this->warnLine('APP_URL is localhost, loopback, or placeholder');
        } else {
            $this->ok('APP_URL is not localhost, loopback, or placeholder');
        }

        $this->warnLine('Trusted proxy and forwarded HTTPS headers cannot be fully proven from CLI');
        $this->addBlocker('production TLS/proxy model not finalized');

        if ($this->usesPilotOrInternalHostname($appUrl)) {
            $this->warnLine('APP_URL uses pilot/internal hostname; broader production rollout still needs review');
            $this->addBlocker('APP_URL uses pilot/internal hostname');
        }
    }

    private function storageEvidenceSection(): void
    {
        $this->section('Storage / Evidence');

        $this->checkPath('Storage path', storage_path(), failWhenBad: true);
        $this->checkPath('Raw archive path', $this->rawArchivePath(), failWhenBad: true);
        $this->checkPath('Downloads path', $this->downloadsPath(), failWhenBad: true);
        $this->checkPath('Backup path', $this->backupPath(), failWhenBad: false);
        $this->checkRecentBackup();

        $this->infoLine('Database is authoritative.');
        $this->infoLine('Raw CSV is archived evidence only.');
        $this->infoLine('Google Drive is backup/sync only.');
    }

    private function securitySecretsSection(SiteTokenStore $siteTokens): void
    {
        $this->section('Security / Secrets');

        $appKeyPresent = trim((string) config('app.key')) !== '';
        $appKeyPresent ? $this->ok('APP_KEY present: yes') : $this->fail('APP_KEY present: no');
        $this->checkDebugStatus();

        try {
            $records = $siteTokens->records();
            $directRecords = $siteTokens->records(SiteTokenStore::TYPE_DIRECT_RUNNER);
            $collectorRecords = $siteTokens->records(SiteTokenStore::TYPE_COLLECTOR);

            $this->infoLine('Site token metadata count: ' . count($records));
            $this->infoLine('Direct runner token type present: ' . ($directRecords !== [] ? 'yes' : 'no'));
            $this->infoLine('Collector token metadata present: ' . ($collectorRecords !== [] ? 'yes' : 'no'));
        } catch (\Throwable) {
            $this->warnLine('Site token metadata could not be safely queried');
        }

        config('inventory.require_site_tokens')
            ? $this->ok('INVENTORY_REQUIRE_SITE_TOKENS is true')
            : $this->warnLine('INVENTORY_REQUIRE_SITE_TOKENS is false (collector/direct intake is open when no tokens are loaded)');
        trim((string) config('inventory.central_intake_token', '')) !== ''
            ? $this->ok('INVENTORY_CENTRAL_INTAKE_TOKEN is set')
            : $this->warnLine('INVENTORY_CENTRAL_INTAKE_TOKEN is empty (/api/intake/* is unauthenticated)');
        if (config('app.env') === 'production' && config('inventory.allow_query_site_token', true)) {
            $this->warnLine('INVENTORY_ALLOW_QUERY_SITE_TOKEN is true (site tokens accepted in URLs)');
        }

        $this->warnLine('Token rotation UI not done');
        $this->warnLine('Per-runner token enrollment not done');
        $this->warnLine('Advanced rate limiting not done');
        $this->infoLine('Secrets are intentionally not displayed.');

        $this->addBlocker('token rotation UI not done');
        $this->addBlocker('per-runner token enrollment not done');
        $this->addBlocker('advanced rate limiting not done');
    }

    private function operationalCommandsSection(): void
    {
        $this->section('Operational Commands');

        $commands = Artisan::all();
        foreach ([
            'inventory:doctor',
            'inventory:direct-pilot-status',
            'inventory:direct-site-kit-audit',
            'inventory:direct-runner-triage',
        ] as $name) {
            array_key_exists($name, $commands)
                ? $this->ok("Command registered: {$name}")
                : $this->fail("Command registered: {$name}");
        }

        $this->infoLine('Run inventory:doctor --production separately before cutover.');
        $this->infoLine('Run inventory:direct-site-kit-audit separately before Direct HTTPS package rollout.');
    }

    private function directHttpsReadinessSection(bool $databaseOk): void
    {
        $this->section('Direct HTTPS Readiness');

        if (! $databaseOk) {
            $this->warnLine('Direct HTTPS runner summary skipped because database readiness checks failed');
            return;
        }

        $freshCutoff = now()->subHours(24);
        $direct = Runner::query()->where('transport_mode', 'direct_https');
        $directRunners = $direct->get(['id', 'runner_id', 'runner_version', 'last_seen_at', 'last_direct_heartbeat_at', 'last_direct_poll_at']);

        $staleCount = $directRunners->filter(fn (Runner $runner): bool => $this->directRunnerIsStale($runner, $freshCutoff))->count();
        $expectedCount = $directRunners->filter(fn (Runner $runner): bool => trim((string) $runner->runner_version) === self::EXPECTED_DIRECT_RUNNER_VERSION)->count();
        $missingVersionCount = $directRunners->filter(fn (Runner $runner): bool => trim((string) $runner->runner_version) === '')->count();
        $oldVersionCount = $directRunners->filter(fn (Runner $runner): bool => $this->isOldDirectRunnerVersion($runner))->count();

        $this->infoLine('Direct HTTPS runner count: ' . $directRunners->count());
        $this->infoLine('Stale Direct HTTPS runner count: ' . $staleCount);
        $this->infoLine('Direct HTTPS runners on expected version ' . self::EXPECTED_DIRECT_RUNNER_VERSION . ' count: ' . $expectedCount);
        $this->infoLine('Direct HTTPS runners missing version count: ' . $missingVersionCount);
        $this->infoLine('Direct HTTPS runners on old version count: ' . $oldVersionCount);

        $blocked = $this->directRepairUpdateBlocked();
        $blocked
            ? $this->infoLine('Direct repair_update blocked status: blocked for Direct HTTPS MVP')
            : $this->warnLine('Direct repair_update blocked status could not be verified');

        $activeDirectRepair = Runner::query()
            ->where('transport_mode', 'direct_https')
            ->whereHas('commands', fn ($query) => $query
                ->where('command_type', 'repair_update')
                ->whereIn('status', ['pending', 'dispatched']))
            ->exists();

        if ($activeDirectRepair) {
            $this->fail('Evidence found of active Direct HTTPS repair_update command');
        }

        if ($staleCount > 0) {
            $this->warnLine('Direct HTTPS runners are stale/offline');
            $this->addBlocker('Direct HTTPS runners stale/offline');
        }

        if ($missingVersionCount > 0 || $oldVersionCount > 0) {
            $this->warnLine('Direct HTTPS runners are not all on ' . self::EXPECTED_DIRECT_RUNNER_VERSION);
            $this->addBlocker('Direct HTTPS runners below expected version');
        }

        $this->warnLine('Direct HTTPS rollout remains manual package refresh/reinstall');
        $this->warnLine('Larger multi-PC/HQ Direct HTTPS rollout is not validated');
        $this->infoLine('Direct HTTPS is for small/no-IT sites first.');
        $this->infoLine('Direct repair_update remains blocked for MVP.');

        $this->addBlocker('larger rollout not validated');
        $this->addBlocker('Direct repair_update unsupported for Direct HTTPS');
    }

    private function collectorShareReadinessSection(bool $databaseOk): void
    {
        $this->section('Collector-share Readiness');

        if (! $databaseOk) {
            $this->warnLine('Collector-share summary skipped because database readiness checks failed');
            return;
        }

        $collectorShareCount = Runner::query()
            ->where(fn ($query) => $query->whereNull('transport_mode')->orWhere('transport_mode', '!=', 'direct_https'))
            ->count();
        $collectorCount = Collector::query()->count();
        $recentCollectorCount = Collector::query()->where('last_seen_at', '>=', now()->subHours(24))->count();

        $this->infoLine('Collector-share runner count: ' . $collectorShareCount);
        $this->infoLine('Collector count: ' . $collectorCount);
        $this->infoLine('Recent collector heartbeat count: ' . $recentCollectorCount);
        $this->infoLine('Collector-share remains main HQ/multi-PC mode.');
        $this->infoLine('Collector-share remains supported.');
    }

    private function cutoverBlockersSection(): void
    {
        $this->section('Cutover Blockers');

        if (config('app.debug')) {
            $this->addBlocker('APP_DEBUG=true');
        }

        if ($this->sqliteProductionDecisionUnresolved()) {
            $this->addBlocker('SQLite production DB decision unresolved');
        }

        $this->addBlocker('backup policy not verified');
        $this->addBlocker('production web-server/process model not finalized');
        $this->addBlocker('production TLS/proxy model not finalized');
        $this->addBlocker('larger rollout not validated');
        $this->addBlocker('Direct repair_update unsupported for Direct HTTPS');

        foreach (array_values(array_unique($this->blockers)) as $blocker) {
            $this->warnLine($blocker);
        }
    }

    private function resultSection(): void
    {
        $this->section('Result');

        if ($this->hasFail) {
            $this->line('Result: FAIL');
            return;
        }

        if ($this->hasWarn) {
            $this->line('Result: WARN');
            return;
        }

        $this->line('Result: PASS');
    }

    private function checkAppUrlConfigured(): void
    {
        trim((string) config('app.url', '')) !== ''
            ? $this->ok('APP_URL configured')
            : $this->fail('APP_URL missing');
    }

    private function checkAppUrlHttps(): void
    {
        $appUrl = strtolower(trim((string) config('app.url', '')));
        $isProduction = config('app.env') === 'production';

        if (str_starts_with($appUrl, 'https://')) {
            $this->ok('APP_URL uses HTTPS');
            return;
        }

        if ($isProduction) {
            $this->fail('APP_URL uses HTTP or is not HTTPS in production');
            return;
        }

        $this->warnLine('APP_URL does not use HTTPS');
    }

    private function checkDebugStatus(): void
    {
        if (! config('app.debug')) {
            $this->ok('APP_DEBUG disabled');
            return;
        }

        if (config('app.env') === 'production') {
            $this->fail('APP_DEBUG=true in production');
            return;
        }

        $this->warnLine('APP_DEBUG=true outside production');
    }

    private function checkAppEnv(): void
    {
        $env = trim((string) config('app.env', ''));

        if ($env === 'production') {
            $this->ok('APP_ENV value: production');
            return;
        }

        $this->warnLine('APP_ENV not production: ' . $this->displayValue($env));
    }

    private function checkTimezone(): void
    {
        trim((string) config('app.timezone', '')) !== ''
            ? $this->ok('Timezone configured')
            : $this->fail('Timezone missing');
    }

    private function checkPath(string $name, string $path, bool $failWhenBad): void
    {
        if ($path !== '' && is_dir($path) && is_writable($path)) {
            $this->ok("{$name} exists and is writable");
            return;
        }

        $message = "{$name} missing or not writable: " . ($path !== '' ? $path : 'not configured');
        $failWhenBad ? $this->fail($message) : $this->warnLine($message);
    }

    private function checkPendingMigrations(): void
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();
            $pending = array_values(array_diff(array_keys($files), $ran));

            if ($pending === []) {
                $this->ok('Pending migrations: none detected');
                return;
            }

            $this->fail('Pending migrations detected: ' . count($pending));
        } catch (\Throwable) {
            $this->fail('Pending migrations could not be safely detected');
        }
    }

    private function checkSqlitePolicy(string $driver): void
    {
        if ($driver !== 'sqlite') {
            $this->ok('SQLite policy: not using sqlite');
            return;
        }

        if (config('app.env') === 'production' && ! (bool) config('inventory.production_sqlite_allowed', false)) {
            $this->fail('SQLite used in production without explicit allowance');
            $this->addBlocker('SQLite production DB decision unresolved');
            return;
        }

        $this->warnLine('SQLite used outside production database configuration');
    }

    private function checkRecentBackup(): void
    {
        $backupPath = $this->backupPath();
        $manifests = is_dir($backupPath)
            ? File::glob($backupPath . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json')
            : [];

        if ($manifests === []) {
            $this->warnLine('No recent backup found or backup history cannot be verified');
            $this->addBlocker('backup policy not verified');
            return;
        }

        $recent = collect($manifests)->contains(fn (string $manifest): bool => File::lastModified($manifest) >= now()->subDays(7)->getTimestamp());
        $recent
            ? $this->ok('Recent backup presence detected')
            : $this->warnLine('No recent backup found within 7 days');
    }

    private function directRunnerIsStale(Runner $runner, CarbonInterface $freshCutoff): bool
    {
        $latest = collect([
            $runner->last_direct_heartbeat_at,
            $runner->last_direct_poll_at,
            $runner->last_seen_at,
        ])->filter()->max();

        return $latest === null || $latest->lt($freshCutoff);
    }

    private function isOldDirectRunnerVersion(Runner $runner): bool
    {
        $version = trim((string) $runner->runner_version);

        return $version !== '' && version_compare($version, self::EXPECTED_DIRECT_RUNNER_VERSION, '<');
    }

    private function directRepairUpdateBlocked(): bool
    {
        return ! (bool) config('inventory.direct_repair_update_enabled', false);
    }

    private function sqliteProductionDecisionUnresolved(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'sqlite'
                && ! (bool) config('inventory.production_sqlite_allowed', false);
        } catch (\Throwable) {
            return false;
        }
    }

    private function rawArchivePath(): string
    {
        return storage_path('app/' . trim((string) config('inventory.raw_archive_path'), '/'));
    }

    private function downloadsPath(): string
    {
        return storage_path('app/' . trim((string) config('inventory.downloads_path'), '/'));
    }

    private function backupPath(): string
    {
        return storage_path('app/' . trim((string) config('inventory.backups_path'), '/'));
    }

    private function isLocalOrPlaceholderUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_contains($host, 'example.')
            || str_contains($host, 'placeholder');
    }

    private function usesPilotOrInternalHostname(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'inventory-pilot')
            || str_contains($host, '.internal.')
            || str_ends_with($host, '.lan');
    }

    private function displayValue(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? 'not configured' : $value;
    }

    private function section(string $name): void
    {
        $this->newLine();
        $this->line($name);
    }

    private function ok(string $message): void
    {
        $this->line("[OK] {$message}");
    }

    private function warnLine(string $message): void
    {
        $this->hasWarn = true;
        $this->line("[WARN] {$message}");
    }

    private function fail(string $message): void
    {
        $this->hasFail = true;
        $this->line("[FAIL] {$message}");
    }

    private function infoLine(string $message): void
    {
        $this->line("[INFO] {$message}");
    }

    private function addBlocker(string $blocker): void
    {
        $this->blockers[] = $blocker;
    }
}
