<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use SplFileInfo;

class DirectSiteKitAudit extends Command
{
    private const PILOT_URL = 'https://inventory-pilot.internal.lan';
    private const STALE_HTTP_URL = 'http://192.168.100.110:8000';
    private const PLACEHOLDER_URL = 'https://inventory.example.local';
    private const EXPECTED_RUNNER_VERSION = '1.0.22';

    protected $signature = 'inventory:direct-site-kit-audit';

    protected $description = 'Read-only Direct HTTPS pilot site-kit safety audit';

    private array $failures = [];

    private array $warnings = [];

    public function handle(): int
    {
        $this->line('Direct HTTPS Site-kit Audit');
        $this->line('Generated at: ' . now()->toDateTimeString());
        $this->newLine();

        $this->line('Environment:');
        $this->environmentLine();
        $this->line('[OK] APP_URL: ' . (string) config('app.url'));
        $this->noticeWarnLine('Official generation note: run official package/site-kit generation only on Supermicro');
        $this->newLine();

        $artifact = $this->findArtifact();
        $this->line('Artifact:');
        if ($artifact === null) {
            $this->failLine('Direct HTTPS site kit not found.');
            $this->line('Hint: Generate official artifacts on Supermicro after profile validation.');
            $this->newLine();
            $this->finalResult();

            return self::FAILURE;
        }

        $this->okLine('Direct HTTPS site kit found: ' . $this->safeRelativePath($artifact));

        $configPath = $artifact . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runner-config.template.json';
        $directReadmePath = $artifact . DIRECTORY_SEPARATOR . 'README_DIRECT_HTTPS_RUNNER.txt';
        $directLauncherPath = $artifact . DIRECTORY_SEPARATOR . 'INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd';
        $directInstallerPath = $artifact . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'install_direct_https_runner.ps1';
        $collectorLauncherPath = $artifact . DIRECTORY_SEPARATOR . 'INSTALL_COLLECTOR_SITE.cmd';
        $readmePaths = $this->readmePaths($artifact);
        if (! is_file($configPath)) {
            $this->failLine('Runner config not found.');
        } else {
            $this->okLine('Runner config found');
        }

        if ($readmePaths === []) {
            $this->warnLine('README not found.');
        } else {
            $this->okLine('README found');
        }

        if (is_file($directReadmePath)) {
            $this->okLine('README_DIRECT_HTTPS_RUNNER.txt found');
        } else {
            $this->failLine('README_DIRECT_HTTPS_RUNNER.txt not found.');
        }

        if (is_file($directLauncherPath)) {
            $this->okLine('INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd found');
        } else {
            $this->failLine('INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd not found.');
        }

        if (is_file($directInstallerPath)) {
            $this->okLine('runner/scripts/install_direct_https_runner.ps1 found');
        } else {
            $this->failLine('runner/scripts/install_direct_https_runner.ps1 not found.');
        }

        if (is_file($collectorLauncherPath)) {
            $this->failLine('Direct HTTPS package exposes INSTALL_COLLECTOR_SITE.cmd as a top-level entry point.');
        } else {
            $this->okLine('Direct HTTPS package does not expose INSTALL_COLLECTOR_SITE.cmd top-level entry point');
        }

        $this->newLine();
        $this->line('Checks:');

        $config = is_file($configPath) ? $this->readJsonObject($configPath) : null;
        if ($config === null) {
            $this->failLine('Runner config could not be read as JSON.');
        } else {
            $this->auditConfig($config);
        }

        $this->auditDirectReadme($directReadmePath);
        $this->auditTextForSecrets(array_merge([$configPath, $directReadmePath, $directLauncherPath], $readmePaths));
        $this->okLine('collector-share mode is not modified by this audit');

        $this->newLine();
        $result = $this->finalResult();

        return $result === 'FAIL' ? self::FAILURE : self::SUCCESS;
    }

    private function environmentLine(): void
    {
        $basePath = base_path();
        if (strcasecmp($basePath, 'D:\\inventory\\laravel') === 0) {
            $this->okLine('Laravel path: ' . $basePath);

            return;
        }

        $this->noticeWarnLine('Laravel path: ' . $basePath . ' (official audit should run on Supermicro at D:\\inventory\\laravel)');
    }

    private function findArtifact(): ?string
    {
        $downloadsRoot = storage_path('app/' . trim((string) config('inventory.downloads_path'), '/'));
        if (! is_dir($downloadsRoot)) {
            return null;
        }

        $candidates = [];
        foreach (glob($downloadsRoot . DIRECTORY_SEPARATOR . 'site-kit-*', GLOB_ONLYDIR) ?: [] as $directory) {
            $configPath = $directory . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runner-config.template.json';
            if (! is_file($configPath)) {
                $candidates[] = ['path' => $directory, 'direct' => false, 'site' => ''];
                continue;
            }

            $config = $this->readJsonObject($configPath);
            $candidates[] = [
                'path' => $directory,
                'direct' => is_array($config) && (($config['transport_mode'] ?? null) === 'direct_https'),
                'site' => is_array($config) ? (string) ($config['siteId'] ?? '') : '',
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $left, array $right): int => [$right['direct'], $right['site'] === 'SITE-HQ', $right['path']] <=> [$left['direct'], $left['site'] === 'SITE-HQ', $left['path']]);

        return $candidates[0]['path'];
    }

    private function readJsonObject(string $path): ?array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function auditConfig(array $config): void
    {
        $transportMode = (string) ($config['transport_mode'] ?? '');
        $serverBaseUrl = trim((string) ($config['serverBaseUrl'] ?? ''));
        $runnerVersion = trim((string) ($config['runnerVersion'] ?? ''));
        $sharedRoot = trim((string) ($config['sharedRoot'] ?? ''));
        $collectorName = trim((string) ($config['collectorName'] ?? ''));

        if ($transportMode === 'direct_https') {
            $this->okLine('transport_mode is direct_https');
        } else {
            $this->failLine("transport_mode is {$this->safeValue($transportMode)}, expected direct_https.");
        }

        if ($serverBaseUrl === self::PILOT_URL) {
            $this->okLine('serverBaseUrl is ' . self::PILOT_URL);
        } elseif ($serverBaseUrl === self::STALE_HTTP_URL || str_starts_with($serverBaseUrl, 'http://')) {
            $this->failLine('serverBaseUrl uses HTTP or stale local endpoint.');
        } elseif ($serverBaseUrl === self::PLACEHOLDER_URL) {
            $this->failLine('serverBaseUrl uses placeholder endpoint.');
        } else {
            $this->failLine('serverBaseUrl is not the current Direct HTTPS pilot endpoint.');
        }

        if (str_starts_with($serverBaseUrl, 'https://')) {
            $this->okLine('serverBaseUrl uses HTTPS');
        } else {
            $this->failLine('serverBaseUrl does not use HTTPS.');
        }

        if ($runnerVersion === self::EXPECTED_RUNNER_VERSION) {
            $this->okLine('runner version is ' . self::EXPECTED_RUNNER_VERSION);
        } elseif ($runnerVersion === '') {
            $this->warnLine('runner version field is missing.');
        } else {
            $this->failLine('runner version is not ' . self::EXPECTED_RUNNER_VERSION . '.');
        }

        if ($serverBaseUrl !== self::STALE_HTTP_URL && ! str_contains($serverBaseUrl, '192.168.100.110:8000')) {
            $this->okLine('no stale HTTP endpoint detected');
        }

        if ($serverBaseUrl !== self::PLACEHOLDER_URL && ! str_contains($serverBaseUrl, 'inventory.example.local')) {
            $this->okLine('no placeholder HTTPS endpoint detected');
        }

        if ($transportMode === 'direct_https' && $sharedRoot === '') {
            $this->okLine('Direct HTTPS active transport does not require collector-share fields');
        } elseif ($transportMode === 'direct_https') {
            $this->warnLine('Direct HTTPS config contains sharedRoot; runner should ignore collector-share fields in direct mode.');
        }

        if ($transportMode === 'direct_https' && $collectorName !== '') {
            $this->warnLine('collectorName is present but not required for Direct HTTPS active transport.');
        }
    }

    private function auditTextForSecrets(array $paths): void
    {
        $secretDetected = false;
        foreach ($paths as $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }

            $text = (string) file_get_contents($path);
            if ($this->containsUnsafeSecret($path, $text)) {
                $secretDetected = true;
            }
        }

        if ($secretDetected) {
            $this->failLine('obvious rendered secret detected; value redacted.');
        } else {
            $this->okLine('no obvious rendered secret detected');
        }
    }

    private function auditDirectReadme(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $text = (string) file_get_contents($path);
        $lower = strtolower($text);

        if (str_contains($text, 'Direct HTTPS')) {
            $this->okLine('Direct HTTPS README contains Direct HTTPS wording');
        } else {
            $this->failLine('Direct HTTPS README does not contain Direct HTTPS wording.');
        }

        if (str_contains($lower, 'small/no-it')) {
            $this->okLine('Direct HTTPS README contains small/no-IT wording');
        } else {
            $this->failLine('Direct HTTPS README does not contain small/no-IT wording.');
        }

        if (str_contains($lower, 'https endpoint') && str_contains($lower, 'trusted certificate')) {
            $this->okLine('Direct HTTPS README contains HTTPS/certificate warning');
        } else {
            $this->failLine('Direct HTTPS README does not contain HTTPS/certificate warning.');
        }

        if (str_contains($text, 'Direct repair_update remains blocked')) {
            $this->okLine('Direct HTTPS README states Direct repair_update remains blocked');
        } else {
            $this->failLine('Direct HTTPS README does not state Direct repair_update remains blocked.');
        }
    }

    private function containsUnsafeSecret(string $path, string $text): bool
    {
        $filename = basename($path);
        $isRunnerConfig = $filename === 'runner-config.template.json';
        if ($isRunnerConfig) {
            $sanitized = preg_replace('/"siteToken"\s*:\s*"[^"]*"/i', '"siteToken":"<redacted>"', $text) ?? $text;
        } else {
            $sanitized = $text;
        }

        $patterns = [
            '/Authorization\s*:\s*Bearer\s+\S+/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{20,}/i',
            '/\bDB_PASSWORD\s*=/i',
            '/\bGOOGLE_[A-Z0-9_]*(SECRET|TOKEN|KEY)\b/i',
            '/\b(token_hash|direct_runner_token|collector_token|site_token)\b\s*[:=]\s*["\']?[A-Za-z0-9._~+\/=-]{12,}/i',
            '/fake[-_ ]?token[-_ ]?secret/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sanitized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function readmePaths(string $artifact): array
    {
        $paths = [];
        foreach (['README_SITE_KIT.md', 'READ_ME_FIRST_FOR_BRANCH.txt', 'README_DIRECT_HTTPS_RUNNER.txt'] as $filename) {
            $path = $artifact . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function safeRelativePath(string $path): string
    {
        $base = base_path();
        $storage = storage_path();
        foreach ([$base, $storage] as $root) {
            if (Str::startsWith(strtolower($path), strtolower($root))) {
                return ltrim(Str::after($path, $root), DIRECTORY_SEPARATOR);
            }
        }

        return (new SplFileInfo($path))->getFilename();
    }

    private function safeValue(string $value): string
    {
        return $value === '' ? 'missing' : $value;
    }

    private function okLine(string $message): void
    {
        $this->line('[OK] ' . $message);
    }

    private function warnLine(string $message): void
    {
        $this->warnings[] = $message;
        $this->line('[WARN] ' . $message);
    }

    private function noticeWarnLine(string $message): void
    {
        $this->line('[WARN] ' . $message);
    }

    private function failLine(string $message): void
    {
        $this->failures[] = $message;
        $this->line('[FAIL] ' . $message);
    }

    private function finalResult(): string
    {
        $result = $this->failures !== [] ? 'FAIL' : ($this->warnings !== [] ? 'WARN' : 'PASS');
        $this->line('Result: ' . $result);

        return $result;
    }
}
