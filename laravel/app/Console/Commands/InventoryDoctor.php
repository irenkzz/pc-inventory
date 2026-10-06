<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Security\SiteTokenStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class InventoryDoctor extends Command
{
    protected $signature = 'inventory:doctor
        {--json : Output machine-readable JSON}
        {--production : Enforce production deployment requirements}';

    protected $description = 'Check Laravel inventory readiness before pilot or cutover';

    public function handle(SiteTokenStore $siteTokens): int
    {
        $production = (bool) $this->option('production');

        $checks = [
            $this->checkLaravelVersion(),
            $this->checkPhpExtensions(),
            $this->checkDatabase(),
            $this->checkInventoryTables(),
            $this->checkStorageWritable('raw archive', storage_path('app/' . trim((string) config('inventory.raw_archive_path'), '/'))),
            $this->checkStorageWritable('downloads', storage_path('app/' . trim((string) config('inventory.downloads_path'), '/'))),
            $this->checkStorageWritable('backups', storage_path('app/' . trim((string) config('inventory.backups_path'), '/'))),
            $this->checkAdminUser(),
            $this->checkSiteTokens($siteTokens, $production),
            $this->checkIntakeAuth($production),
            $this->checkProductionSettings($production),
            $this->checkProductionRuntime($production),
            $this->checkBackupHistory($production),
        ];

        $failed = collect($checks)->where('status', 'fail')->count();

        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => $failed === 0 ? 'ok' : 'fail',
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Status', 'Check', 'Message'], array_map(
            fn (array $check): array => [strtoupper($check['status']), $check['name'], $check['message']],
            $checks,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function passCheck(string $name, string $message): array
    {
        return ['status' => 'pass', 'name' => $name, 'message' => $message];
    }

    private function warnCheck(string $name, string $message): array
    {
        return ['status' => 'warn', 'name' => $name, 'message' => $message];
    }

    private function failCheck(string $name, string $message): array
    {
        return ['status' => 'fail', 'name' => $name, 'message' => $message];
    }

    private function checkLaravelVersion(): array
    {
        $version = app()->version();

        return str_starts_with($version, '10.')
            ? $this->passCheck('Laravel version', $version)
            : $this->failCheck('Laravel version', "Expected Laravel 10.x, got {$version}");
    }

    private function checkPhpExtensions(): array
    {
        $missing = array_values(array_filter(['pdo', 'mbstring', 'openssl', 'json', 'zip'], fn (string $extension): bool => ! extension_loaded($extension)));

        return $missing === []
            ? $this->passCheck('PHP extensions', 'Required extensions are loaded.')
            : $this->failCheck('PHP extensions', 'Missing: ' . implode(', ', $missing));
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return $this->passCheck('Database connection', config('database.default'));
        } catch (\Throwable $exception) {
            return $this->failCheck('Database connection', $exception->getMessage());
        }
    }

    private function checkInventoryTables(): array
    {
        $tables = ['devices', 'device_scans', 'hardware_snapshots', 'change_log', 'raw_files', 'runners', 'collectors', 'runner_commands'];
        $missing = array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));

        return $missing === []
            ? $this->passCheck('Inventory tables', 'Core tables exist.')
            : $this->failCheck('Inventory tables', 'Missing: ' . implode(', ', $missing));
    }

    private function checkStorageWritable(string $name, string $path): array
    {
        try {
            File::ensureDirectoryExists($path);
            $probe = $path . DIRECTORY_SEPARATOR . '.write-test';
            File::put($probe, 'ok');
            File::delete($probe);

            return $this->passCheck("Storage: {$name}", $path);
        } catch (\Throwable $exception) {
            return $this->failCheck("Storage: {$name}", $exception->getMessage());
        }
    }

    private function checkAdminUser(): array
    {
        try {
            return User::query()->exists()
                ? $this->passCheck('Admin user', 'At least one admin user exists.')
                : $this->warnCheck('Admin user', 'No users found. Run php artisan db:seed or create an admin user.');
        } catch (\Throwable $exception) {
            return $this->failCheck('Admin user', $exception->getMessage());
        }
    }

    private function checkSiteTokens(SiteTokenStore $siteTokens, bool $production): array
    {
        $tokens = $siteTokens->all();
        $source = $siteTokens->source();
        $required = (bool) config('inventory.require_site_tokens');

        if ($tokens !== []) {
            if ($production && ! $required) {
                return $this->failCheck('Site tokens', 'Production requires INVENTORY_REQUIRE_SITE_TOKENS=true.');
            }

            return $this->passCheck('Site tokens', 'Loaded ' . count($tokens) . " site token(s) from {$source}.");
        }

        if ($required || $production) {
            return $this->failCheck('Site tokens', "Site tokens are required but none were loaded from {$source}.");
        }

        return $this->warnCheck('Site tokens', "No site token map found. Collector endpoints will allow any token until configured: {$source}");
    }

    private function checkIntakeAuth(bool $production): array
    {
        $warnings = [];

        if (! config('inventory.require_site_tokens')) {
            $warnings[] = 'INVENTORY_REQUIRE_SITE_TOKENS is false (collector/direct intake is open when no tokens are loaded)';
        }

        if (trim((string) config('inventory.central_intake_token', '')) === '') {
            $warnings[] = 'INVENTORY_CENTRAL_INTAKE_TOKEN is empty (/api/intake/* is unauthenticated)';
        }

        if ($production && config('inventory.allow_query_site_token', true)) {
            $warnings[] = 'INVENTORY_ALLOW_QUERY_SITE_TOKEN is true (site tokens accepted in URLs)';
        }

        return $warnings === []
            ? $this->passCheck('Intake auth', 'Strict intake authentication is configured.')
            : $this->warnCheck('Intake auth', implode('; ', $warnings));
    }

    private function checkProductionSettings(bool $production): array
    {
        $failures = [];
        $warnings = [];

        if ($production && config('app.env') !== 'production') {
            $failures[] = 'APP_ENV must be production';
        }

        if ($production && trim((string) config('app.key')) === '') {
            $failures[] = 'APP_KEY is empty';
        }

        if (config('app.debug')) {
            $message = 'APP_DEBUG=true';
            if ($production) {
                $failures[] = $message;
            } else {
                $warnings[] = $message;
            }
        }

        if (str_contains((string) config('app.url'), '127.0.0.1') || str_contains((string) config('app.url'), 'localhost')) {
            $message = 'APP_URL is local';
            if ($production) {
                $failures[] = $message;
            } else {
                $warnings[] = $message;
            }
        }

        if ($failures !== []) {
            return $this->failCheck('Production settings', implode('; ', $failures));
        }

        if ($warnings !== []) {
            return $this->warnCheck('Production settings', implode('; ', $warnings));
        }

        if ($production && ! str_starts_with((string) config('app.url'), 'https://')) {
            return $this->warnCheck('Production settings', 'APP_URL is not HTTPS. Use HTTPS unless the portal is behind a trusted internal TLS terminator.');
        }

        if ($production) {
            return $this->passCheck('Production settings', 'Production environment settings are present.');
        }

        return $this->passCheck('Production settings', 'No obvious local/debug settings detected.');
    }

    private function checkProductionRuntime(bool $production): array
    {
        if (! $production) {
            return $this->passCheck('Runtime mode', 'Production runtime checks skipped. Use --production before deployment.');
        }

        $messages = [];

        if (config('database.default') === 'sqlite') {
            $messages[] = 'DB_CONNECTION=sqlite';
        }

        if (config('queue.default') === 'sync') {
            $messages[] = 'QUEUE_CONNECTION=sync';
        }

        if (config('cache.default') === 'file') {
            $messages[] = 'CACHE_DRIVER=file';
        }

        if ($messages === []) {
            return $this->passCheck('Runtime mode', 'Database, queue, and cache settings are not using local defaults.');
        }

        return $this->warnCheck('Runtime mode', implode('; ', $messages) . '. Acceptable for a small internal pilot, but review before wider rollout.');
    }

    private function checkBackupHistory(bool $production): array
    {
        if (! $production) {
            return $this->passCheck('Backup history', 'Backup history check skipped. Use --production before deployment.');
        }

        $backupRoot = storage_path('app/' . trim((string) config('inventory.backups_path'), '/'));
        $manifests = is_dir($backupRoot) ? File::glob($backupRoot . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') : [];

        if ($manifests !== []) {
            return $this->passCheck('Backup history', 'Found ' . count($manifests) . ' backup manifest(s).');
        }

        return $this->warnCheck('Backup history', 'No backup manifests found. Run php artisan inventory:backup --label=pre-deployment before cutover.');
    }
}
