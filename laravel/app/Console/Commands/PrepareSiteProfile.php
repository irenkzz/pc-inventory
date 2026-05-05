<?php

namespace App\Console\Commands;

use App\Services\Deployment\SiteKitProfileValidator;
use App\Services\Security\SiteTokenStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PrepareSiteProfile extends Command
{
    protected $signature = 'inventory:prepare-site-profile
        {profile : Source JSON deployment profile path}
        {--output= : Output profile path. Defaults to deployment/profiles/generated/SITE-ID.json}
        {--in-place : Update the source profile in place}
        {--site-token= : Site token to write. Defaults to registered token for the profile site_id}
        {--direct-runner-token= : Direct runner token to write. Defaults to registered direct_runner token for the profile site_id}
        {--transport-mode= : Override transport_mode}
        {--server-base-url= : Override server_base_url}
        {--share-root= : Override share_root}
        {--strict : Treat validation warnings as deployment-blocking failures}';

    protected $description = 'Prepare a pilot site profile by injecting registered token and deployment overrides';

    public function handle(SiteKitProfileValidator $validator, SiteTokenStore $siteTokens): int
    {
        try {
            $sourcePath = $validator->resolvePath((string) $this->argument('profile'));
            $profile = $validator->load((string) $this->argument('profile'));
            if ($this->option('transport-mode') !== null && trim((string) $this->option('transport-mode')) !== '') {
                $profile['transport_mode'] = trim((string) $this->option('transport-mode'));
            }
            $siteId = trim((string) ($profile['site_id'] ?? ''));
            $transportMode = trim((string) ($profile['transport_mode'] ?? 'collector_share')) ?: 'collector_share';
            $siteToken = $transportMode === 'direct_https'
                ? $this->resolveDirectRunnerToken($siteId, $siteTokens)
                : $this->resolveSiteToken($siteId, $siteTokens);
        } catch (\Throwable $exception) {
            return $this->failResult((string) $this->argument('profile'), [$exception->getMessage()]);
        }

        if ($siteId === '') {
            return $this->failResult($sourcePath, ['site_id is required before a profile can be prepared']);
        }

        if ($siteToken === '' && $transportMode === 'direct_https') {
            return $this->failResult($sourcePath, ["No direct runner token provided and no registered direct_runner token found for {$siteId}."], $siteId);
        }

        if ($siteToken === '') {
            return $this->failResult($sourcePath, ["No site token provided and no registered token found for {$siteId}."], $siteId);
        }

        if ($transportMode === 'direct_https') {
            $profile['direct_runner_token'] = $siteToken;
        } else {
            $profile['site_token'] = $siteToken;
        }

        if ($this->option('server-base-url') !== null && trim((string) $this->option('server-base-url')) !== '') {
            $profile['server_base_url'] = trim((string) $this->option('server-base-url'));
        }

        if ($this->option('share-root') !== null && trim((string) $this->option('share-root')) !== '') {
            $profile['share_root'] = trim((string) $this->option('share-root'));
        }

        $result = $validator->validate($profile);
        $errors = $result['errors'];
        if ($this->option('strict') && $result['warnings'] !== []) {
            $errors = array_merge($errors, $result['warnings']);
        }

        $outputPath = $this->outputPath($validator, $sourcePath, $siteId);
        if ($errors === []) {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $status = $errors === [] ? 'ok' : 'fail';
        $this->line(json_encode([
            'status' => $status,
            'source_profile' => $sourcePath,
            'output_profile' => $errors === [] ? $outputPath : null,
            'site_id' => $siteId,
            'errors' => $errors,
            'warnings' => $result['warnings'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $status === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    private function resolveSiteToken(string $siteId, SiteTokenStore $siteTokens): string
    {
        $explicit = trim((string) ($this->option('site-token') ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return trim((string) ($siteTokens->all()[$siteId] ?? ''));
    }

    private function resolveDirectRunnerToken(string $siteId, SiteTokenStore $siteTokens): string
    {
        $explicit = trim((string) ($this->option('direct-runner-token') ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return trim((string) ($siteTokens->all(SiteTokenStore::TYPE_DIRECT_RUNNER)[$siteId] ?? ''));
    }

    private function outputPath(SiteKitProfileValidator $validator, string $sourcePath, string $siteId): string
    {
        if ($this->option('in-place')) {
            return $sourcePath;
        }

        $output = trim((string) ($this->option('output') ?? ''));
        if ($output === '') {
            $output = "deployment/profiles/generated/{$siteId}.json";
        }

        return $validator->resolveOutputPath($output);
    }

    private function failResult(string $profile, array $errors, ?string $siteId = null): int
    {
        $this->line(json_encode([
            'status' => 'fail',
            'source_profile' => $profile,
            'output_profile' => null,
            'site_id' => $siteId,
            'errors' => $errors,
            'warnings' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }
}
