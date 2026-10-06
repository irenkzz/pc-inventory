<?php

namespace App\Console\Commands;

use App\Services\Security\SiteTokenStore;
use Illuminate\Console\Command;

class ListSiteTokens extends Command
{
    protected $signature = 'inventory:site-tokens
        {--type=collector : Token type: collector, direct_runner, or all}
        {--reveal : Show full token values}';

    protected $description = 'List configured site tokens, masked by default';

    public function handle(SiteTokenStore $store): int
    {
        $type = trim((string) $this->option('type')) ?: SiteTokenStore::TYPE_COLLECTOR;
        if (! in_array($type, [SiteTokenStore::TYPE_COLLECTOR, SiteTokenStore::TYPE_DIRECT_RUNNER, 'all'], true)) {
            $this->error('type must be collector, direct_runner, or all.');
            return self::FAILURE;
        }

        $records = $store->records($type === 'all' ? null : $type);

        $this->line('Source: ' . $store->source());

        if ($records === []) {
            $this->warn('No site tokens configured.');
            return self::SUCCESS;
        }

        $this->table(['Site', 'Type', 'Token', 'Last used', 'Expires', 'Revoked'], collect($records)
            ->map(fn (array $record): array => [
                $record['site_id'],
                $record['token_type'],
                $this->option('reveal') ? $record['token'] : $this->mask($record['token']),
                $record['last_used_at'] ?? '-',
                $record['expires_at'] ?? '-',
                $record['revoked_at'] ?? '-',
            ])
            ->values()
            ->all());

        return self::SUCCESS;
    }

    private function mask(string $token): string
    {
        if (strlen($token) <= 8) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 4) . str_repeat('*', max(4, strlen($token) - 8)) . substr($token, -4);
    }
}
