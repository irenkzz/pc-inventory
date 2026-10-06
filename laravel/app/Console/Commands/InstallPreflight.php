<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class InstallPreflight extends Command
{
    private const SUPERMICRO_BASE_PATH = 'D:\\inventory\\laravel';
    private const IT_ADMIN_REPO_PATH = 'D:\\xampp\\htdocs\\inventaris';

    protected $signature = 'inventory:install-preflight';

    protected $description = 'Read-only installer and productized setup preflight checklist';

    private bool $hasFail = false;

    private bool $hasWarn = false;

    private ?string $hostKind = null;

    public function handle(): int
    {
        $this->hasFail = false;
        $this->hasWarn = false;
        $this->hostKind = null;

        $this->line('Inventory installer/server preflight');
        $this->infoLine('This is a read-only preflight for future productized installation, setup wizard, and package/site-kit generation flows.');
        $this->infoLine('This is not an installer and does not generate packages or site kits.');

        $this->environmentSection();
        $this->laravelHostSection();
        $this->applicationUrlSection();
        $this->storagePathsSection();
        $this->operationalCommandsSection();
        $this->packageSafetySection();
        $this->directHttpsNotesSection();
        $this->collectorShareNotesSection();
        $this->setupWizardSection();
        $this->manualBoundariesSection();
        $this->securitySection();
        $this->recommendedNextChecksSection();
        $this->resultSection();

        return $this->hasFail ? self::FAILURE : self::SUCCESS;
    }

    private function environmentSection(): void
    {
        $this->section('Environment');

        $env = trim((string) config('app.env', ''));
        $debug = (bool) config('app.debug');

        $this->infoLine('APP_ENV: ' . $this->displayValue($env));
        $this->infoLine('APP_DEBUG: ' . ($debug ? 'true' : 'false'));
        $this->infoLine('PHP version: ' . PHP_VERSION);
        $this->infoLine('Laravel version: ' . app()->version());
        $this->infoLine('OS family: ' . PHP_OS_FAMILY);
        $this->infoLine('Hostname/computer name: ' . $this->displayValue($this->hostname()));
        $this->infoLine('Laravel base path: ' . $this->basePath());

        $this->checkAppEnv($env);
        $this->checkAppDebug($env, $debug);
    }

    private function laravelHostSection(): void
    {
        $this->section('Laravel Host');

        $kind = $this->hostKind();

        if ($kind === 'supermicro') {
            $this->ok('Host/path appears to be the approved Supermicro active Laravel host');
        } elseif ($kind === 'it-admin') {
            $this->warnLine('Host/path appears to be the IT-ADMIN development repo');
        } else {
            $this->warnLine('Host/path is unknown for productized package/site-kit generation');
        }

        $this->infoLine('Approved active Laravel host heuristic: ' . self::SUPERMICRO_BASE_PATH);
        $this->infoLine('IT-ADMIN source repo heuristic: ' . self::IT_ADMIN_REPO_PATH);
    }

    private function applicationUrlSection(): void
    {
        $this->section('Application URL / HTTPS');

        $appUrl = trim((string) config('app.url', ''));
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));

        $this->infoLine('APP_URL configured status: ' . ($appUrl === '' ? 'missing' : 'present'));

        if ($appUrl === '') {
            $this->fail('APP_URL missing');
            return;
        }

        str_starts_with(strtolower($appUrl), 'https://')
            ? $this->ok('APP_URL uses HTTPS')
            : $this->fail('APP_URL uses plain HTTP or is not HTTPS for package-generation use');

        $this->isPlaceholderUrl($appUrl)
            ? $this->fail('APP_URL is placeholder/example and is not valid for package-generation use')
            : $this->ok('APP_URL is not placeholder/example');

        $this->isLocalUrlHost($host)
            ? $this->fail('APP_URL is localhost/loopback and is not valid for package-generation use')
            : $this->ok('APP_URL is not localhost/loopback');

        if ($this->usesPilotOrInternalHostname($host)) {
            $this->warnLine('APP_URL uses pilot/internal hostname; productized rollout still needs review');
        }

        $this->warnLine('Trusted proxy and forwarded HTTPS headers cannot be fully proven from CLI');
    }

    private function storagePathsSection(): void
    {
        $this->section('Storage and Package Paths');

        $approvedHost = $this->hostKind() === 'supermicro';

        $this->checkPath('Laravel storage path', storage_path(), failWhenBad: true);
        $this->checkPath('Inventory storage root', storage_path('app/inventory'), failWhenBad: false);
        $this->checkPath('Inventory downloads/site-kit path', $this->downloadsPath(), failWhenBad: $approvedHost);
        $this->checkPath('Raw archive path', $this->rawArchivePath(), failWhenBad: true);
        $this->checkPath('Backup path', $this->backupPath(), failWhenBad: false);
        $this->warnLine('Backup policy and restore rehearsal are not verified by this command');
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
            'inventory:production-readiness',
        ] as $command) {
            array_key_exists($command, $commands)
                ? $this->ok("Command registered: {$command}")
                : $this->fail("Command registered: {$command}");
        }

        foreach ([
            'inventory:build-site-kit',
            'inventory:prepare-site-profile',
            'inventory:validate-site-profile',
            'inventory:backup',
        ] as $command) {
            array_key_exists($command, $commands)
                ? $this->infoLine("Optional command registered: {$command}")
                : $this->warnLine("Optional command not registered: {$command}");
        }

        $this->infoLine('Command availability only is checked; nested Artisan commands are not run.');
    }

    private function packageSafetySection(): void
    {
        $this->section('Package / Site-kit Generation Safety');

        $kind = $this->hostKind();

        if ($kind === 'supermicro') {
            $this->ok('Official package/site-kit generation allowed on approved Supermicro active Laravel host');
        } elseif ($kind === 'it-admin') {
            $this->fail('Official package/site-kit generation blocked on IT-ADMIN development repo');
        } else {
            $this->warnLine('Official package/site-kit generation blocked until host/path is approved');
        }

        $this->infoLine('Official package/site-kit generation is blocked outside the approved host.');
        $this->infoLine('This command does not generate packages, site kits, backups, or tokens.');
    }

    private function directHttpsNotesSection(): void
    {
        $this->section('Direct HTTPS Productization Notes');

        $this->infoLine('Direct HTTPS is for small/no-IT sites.');
        $this->infoLine('Run inventory:direct-site-kit-audit before installing more Direct HTTPS runners.');
        $this->infoLine('Direct repair_update remains blocked for Direct HTTPS MVP.');
        $this->infoLine('Direct HTTPS API contracts must not change.');
        $this->infoLine('Commands remain async: poll means delivery; ACK means execution result.');
        $this->infoLine('Do not use collector tokens for Direct HTTPS.');
    }

    private function collectorShareNotesSection(): void
    {
        $this->section('Collector-share Productization Notes');

        $this->infoLine('Collector-share remains supported.');
        $this->infoLine('Collector-share remains the main HQ/multi-PC mode.');
        $this->infoLine('Do not introduce Direct HTTPS assumptions into collector-share packages.');
    }

    private function setupWizardSection(): void
    {
        $this->section('Setup Wizard Readiness');

        $this->ok('Portal Setup Wizard MVP is implemented as authenticated read-only guidance at /setup-wizard.');
        $this->infoLine('User/site/token creation and production configuration remain manual.');
    }

    private function manualBoundariesSection(): void
    {
        $this->section('MVP Manual Boundaries');

        foreach ([
            'MariaDB/MySQL installation',
            'DB migration execution',
            'IIS + PHP FastCGI setup',
            'HPE StoreEasy/proxy configuration',
            'DNS/certificate setup',
            'Windows certificate trust deployment',
            'Backup target setup',
            'Restore rehearsal',
            '.env changes',
            'APP_KEY handling',
            'Production cutover approval',
            'Token rotation',
            'Per-runner token enrollment',
            'Direct repair_update',
        ] as $boundary) {
            $this->infoLine($boundary . ' remains manual in MVP.');
        }
    }

    private function securitySection(): void
    {
        $this->section('Security / Secret Redaction');

        $this->infoLine('Support output must never expose token secrets, siteToken, collector tokens, direct runner tokens, bearer tokens, token hashes, DB credentials, Google credentials, raw CSV contents, command payload JSON, .env values, APP_KEY values, full configs, or full runner GUIDs.');
        $this->ok('This command reports secret presence/status only and does not print secret values.');
    }

    private function recommendedNextChecksSection(): void
    {
        $this->section('Recommended Next Checks');

        $this->infoLine('Run inventory:doctor separately for operational diagnostics.');
        $this->infoLine('Run inventory:production-readiness before production or cutover review.');
        $this->infoLine('Run inventory:direct-site-kit-audit before Direct HTTPS package rollout.');
        $this->infoLine('Review docs/INSTALLATION_PRODUCTIZATION_STRATEGY.md before installation or productization changes.');
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

    private function checkAppEnv(string $env): void
    {
        if ($env === 'production') {
            $this->ok('APP_ENV=production');
            return;
        }

        if (in_array($env, ['local', 'testing', 'test', 'pilot'], true) || str_contains($env, 'pilot')) {
            $this->warnLine('APP_ENV is local/testing/pilot-like: ' . $this->displayValue($env));
            return;
        }

        $this->warnLine('APP_ENV is not production: ' . $this->displayValue($env));
    }

    private function checkAppDebug(string $env, bool $debug): void
    {
        if (! $debug) {
            $this->ok('APP_DEBUG=false');
            return;
        }

        if ($env === 'production') {
            $this->fail('APP_ENV=production with APP_DEBUG=true');
            return;
        }

        $this->warnLine('APP_DEBUG=true outside production');
    }

    private function checkPath(string $name, string $path, bool $failWhenBad): void
    {
        $path = trim($path);

        if ($path !== '' && is_dir($path) && is_readable($path) && is_writable($path)) {
            $this->ok("{$name} exists, readable, and writable");
            return;
        }

        $message = "{$name} missing, unreadable, or unwritable: " . ($path !== '' ? $path : 'not configured');
        $failWhenBad ? $this->fail($message) : $this->warnLine($message);
    }

    private function hostKind(): string
    {
        if ($this->hostKind !== null) {
            return $this->hostKind;
        }

        $basePath = $this->normalizePath($this->basePath());

        if ($basePath === $this->normalizePath(self::SUPERMICRO_BASE_PATH)) {
            return $this->hostKind = 'supermicro';
        }

        if ($basePath === $this->normalizePath(self::IT_ADMIN_REPO_PATH)
            || str_starts_with($basePath, $this->normalizePath(self::IT_ADMIN_REPO_PATH . '\\'))
            || str_contains($basePath, '\\xampp\\htdocs\\inventaris\\')) {
            return $this->hostKind = 'it-admin';
        }

        return $this->hostKind = 'unknown';
    }

    private function basePath(): string
    {
        $override = config('inventory.install_preflight_base_path_override');

        return is_string($override) && trim($override) !== ''
            ? trim($override)
            : base_path();
    }

    private function hostname(): string
    {
        $override = config('inventory.install_preflight_hostname_override');
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        return (string) (gethostname() ?: ($_SERVER['COMPUTERNAME'] ?? ''));
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

    private function isPlaceholderUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === ''
            || str_contains($host, 'example.')
            || str_contains($host, 'placeholder')
            || str_contains($host, 'example-');
    }

    private function isLocalUrlHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function usesPilotOrInternalHostname(string $host): bool
    {
        return str_contains($host, 'inventory-pilot')
            || str_contains($host, '.internal.')
            || str_ends_with($host, '.lan');
    }

    private function normalizePath(string $path): string
    {
        return strtolower(rtrim(str_replace('/', '\\', $path), '\\'));
    }

    private function displayValue(string $value): string
    {
        $value = trim($value);

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
}
