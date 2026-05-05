<?php

namespace App\Console\Commands;

use App\Services\Security\SiteTokenStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RegisterSiteToken extends Command
{
    protected $signature = 'inventory:register-site-token
        {site_id}
        {--site-token=}
        {--type=collector : Token type: collector or direct_runner}
        {--reveal : Show the token value in command output}';

    protected $description = 'Add or update a site token used by branch relays or direct HTTPS runners';

    public function handle(SiteTokenStore $siteTokens): int
    {
        $path = $siteTokens->filePath();
        $tokens = [];

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $tokens = is_array($decoded) ? $decoded : [];
        }

        $siteId = trim((string) $this->argument('site_id'));
        $token = trim((string) ($this->option('site-token') ?: Str::random(48)));
        $tokenType = trim((string) $this->option('type')) ?: SiteTokenStore::TYPE_COLLECTOR;

        if ($siteId === '') {
            $this->error('site_id is required.');
            return self::FAILURE;
        }

        if (! in_array($tokenType, [SiteTokenStore::TYPE_COLLECTOR, SiteTokenStore::TYPE_DIRECT_RUNNER], true)) {
            $this->error('type must be collector or direct_runner.');
            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        $tokens = $this->withToken($tokens, $siteId, $tokenType, $token);
        file_put_contents($path, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $output = [
            'site_id' => $siteId,
            'token_type' => $tokenType,
            'token_file' => $path,
            'token_generated' => ! $this->option('site-token'),
        ];

        if ($this->option('reveal')) {
            $output['site_token'] = $token;
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function withToken(array $tokens, string $siteId, string $tokenType, string $token): array
    {
        $existing = $tokens[$siteId] ?? null;

        if ($tokenType === SiteTokenStore::TYPE_COLLECTOR && ($existing === null || is_string($existing))) {
            $tokens[$siteId] = $token;

            return $tokens;
        }

        $siteTokens = $this->siteTokensForWrite($existing);
        $siteTokens[$tokenType] = $token;
        $tokens[$siteId] = $siteTokens;

        return $tokens;
    }

    private function siteTokensForWrite(mixed $existing): array
    {
        if (is_string($existing) && trim($existing) !== '') {
            return [
                SiteTokenStore::TYPE_COLLECTOR => $existing,
            ];
        }

        if (! is_array($existing)) {
            return [];
        }

        if (array_key_exists('token', $existing)) {
            $tokenType = (string) ($existing['token_type'] ?? SiteTokenStore::TYPE_COLLECTOR);
            $token = trim((string) ($existing['token'] ?? ''));

            return $token === '' ? [] : [$tokenType => $token];
        }

        return $existing;
    }
}
