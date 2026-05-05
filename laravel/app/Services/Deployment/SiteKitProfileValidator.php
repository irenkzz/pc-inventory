<?php

namespace App\Services\Deployment;

use App\Services\Security\SiteTokenStore;

class SiteKitProfileValidator
{
    private const COLLECTOR_REQUIRED_STRING_FIELDS = [
        'site_id',
        'site_name',
        'collector_name',
        'share_root',
        'server_base_url',
        'site_token',
    ];

    private const DIRECT_REQUIRED_STRING_FIELDS = [
        'site_id',
        'site_name',
        'server_base_url',
    ];

    private const TRANSPORT_MODES = ['collector_share', 'direct_https'];

    private const DIRECT_HTTP_LOCAL_WARNING = 'Direct HTTPS profile uses plain HTTP. This is allowed only for local development smoke testing. Do not use for pilot, production, or cross-network deployment.';

    private const DIRECT_HTTP_REMOTE_ERROR = 'Direct HTTPS runner mode requires an https:// serverBaseUrl for pilot/production site kits. Plain HTTP may expose direct_runner tokens, inventory payloads, and command polling/ACK traffic.';

    private const INTEGER_FIELDS = [
        'scan_interval_minutes' => [15, 10080],
        'collector_poll_interval_minutes' => [1, 1440],
        'task_random_delay_minutes' => [0, 1440],
    ];

    public function __construct(private readonly SiteTokenStore $siteTokens)
    {
    }

    public function load(string $path): array
    {
        $resolved = $this->resolvePath($path);
        if (! is_file($resolved)) {
            throw new \InvalidArgumentException("Profile not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException("Profile must be valid JSON object: {$path}");
        }

        return $decoded;
    }

    public function validate(array $profile): array
    {
        $errors = [];
        $warnings = [];

        $transportMode = $this->transportMode($profile);
        if (! in_array($transportMode, self::TRANSPORT_MODES, true)) {
            $errors[] = 'transport_mode must be collector_share or direct_https';
            $transportMode = 'collector_share';
        }

        $requiredFields = $transportMode === 'direct_https'
            ? self::DIRECT_REQUIRED_STRING_FIELDS
            : self::COLLECTOR_REQUIRED_STRING_FIELDS;

        if ($transportMode === 'direct_https' && $this->boolValue($profile['collector_required'] ?? false)) {
            $requiredFields[] = 'collector_name';
            $requiredFields[] = 'share_root';
            $requiredFields[] = 'site_token';
        }

        foreach ($requiredFields as $field) {
            if (trim((string) ($profile[$field] ?? '')) === '') {
                $errors[] = "{$field} is required";
            }
        }

        foreach (self::INTEGER_FIELDS as $field => [$min, $max]) {
            if (! array_key_exists($field, $profile)) {
                continue;
            }

            $value = filter_var($profile[$field], FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > $max) {
                $errors[] = "{$field} must be an integer between {$min} and {$max}";
            }
        }

        $siteId = trim((string) ($profile['site_id'] ?? ''));
        if ($siteId !== '' && ! preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $siteId)) {
            $errors[] = 'site_id should use uppercase letters, numbers, underscore, or dash';
        }

        $shareRoot = trim((string) ($profile['share_root'] ?? ''));
        if ($shareRoot !== '' && ! str_starts_with($shareRoot, '\\\\')) {
            $warnings[] = 'share_root does not look like a UNC path';
        }

        $serverBaseUrl = trim((string) ($profile['server_base_url'] ?? ''));
        if ($serverBaseUrl !== '') {
            if (filter_var($serverBaseUrl, FILTER_VALIDATE_URL) === false) {
                $errors[] = 'server_base_url must be a valid URL';
            } elseif ($transportMode === 'direct_https' && $this->isPlainHttpUrl($serverBaseUrl)) {
                if ($this->isLocalHttpUrl($serverBaseUrl)) {
                    $warnings[] = self::DIRECT_HTTP_LOCAL_WARNING;
                } else {
                    $errors[] = self::DIRECT_HTTP_REMOTE_ERROR;
                }
            } elseif (str_contains($serverBaseUrl, ':5000')) {
                $warnings[] = 'server_base_url appears to point at the legacy Flask port 5000';
            } elseif (str_contains($serverBaseUrl, '127.0.0.1') || str_contains($serverBaseUrl, 'localhost')) {
                $warnings[] = 'server_base_url is local; use the Laravel URL reachable by collectors before pilot';
            } elseif (! str_starts_with($serverBaseUrl, 'https://') && ! $this->usesPrivateIpHost($serverBaseUrl)) {
                $warnings[] = 'server_base_url is not HTTPS; use HTTPS unless this is a trusted internal pilot network';
            }
        }

        if ($transportMode === 'direct_https') {
            $directRunnerToken = trim((string) ($profile['direct_runner_token'] ?? ''));
            if ($directRunnerToken === '' && $this->registeredDirectRunnerToken($siteId) === '') {
                $errors[] = 'direct_runner_token is required for direct_https profiles';
            }

            if ($directRunnerToken !== '' && strlen($directRunnerToken) < 24) {
                $warnings[] = 'direct_runner_token is short; use a generated direct runner token before pilot';
            }

            if (str_contains(strtolower($directRunnerToken), 'replace-this') || str_contains(strtolower($directRunnerToken), 'change-this')) {
                $warnings[] = 'direct_runner_token is still a placeholder';
            }
        }

        $siteToken = trim((string) ($profile['site_token'] ?? ''));
        if ($siteToken !== '' && strlen($siteToken) < 24) {
            $warnings[] = 'site_token is short; use a generated site token before pilot';
        }

        if (str_contains(strtolower($siteToken), 'replace-this') || str_contains(strtolower($siteToken), 'change-this')) {
            $warnings[] = 'site_token is still a placeholder';
        }

        return [
            'status' => $errors === [] ? 'ok' : 'fail',
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $this->isAbsolutePath($path)) {
            return $path;
        }

        $insideLaravel = base_path($path);
        if (is_file($insideLaravel)) {
            return $insideLaravel;
        }

        return base_path('../' . ltrim($path, '\\/'));
    }

    public function resolveOutputPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path('../' . ltrim($path, '\\/'));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function usesPrivateIpHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function isPlainHttpUrl(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http';
    }

    private function isLocalHttpUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1'], true);
    }

    private function transportMode(array $profile): string
    {
        $transportMode = trim((string) ($profile['transport_mode'] ?? 'collector_share'));

        return $transportMode === '' ? 'collector_share' : $transportMode;
    }

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function registeredDirectRunnerToken(string $siteId): string
    {
        if ($siteId === '') {
            return '';
        }

        return trim((string) ($this->siteTokens->all(SiteTokenStore::TYPE_DIRECT_RUNNER)[$siteId] ?? ''));
    }
}
