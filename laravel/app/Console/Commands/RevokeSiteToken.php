<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Security\SiteTokenStore;
use Illuminate\Console\Command;

class RevokeSiteToken extends Command
{
    protected $signature = 'inventory:revoke-site-token
        {site_id}
        {--type=collector : Token type: collector or direct_runner}';

    protected $description = 'Mark a site token revoked (record is kept, token no longer verifies)';

    public function handle(SiteTokenStore $store): int
    {
        $type = trim((string) $this->option('type')) ?: SiteTokenStore::TYPE_COLLECTOR;
        $site = trim((string) $this->argument('site_id'));

        if (! in_array($type, [SiteTokenStore::TYPE_COLLECTOR, SiteTokenStore::TYPE_DIRECT_RUNNER], true)) {
            $this->error('type must be collector or direct_runner.');
            return self::FAILURE;
        }

        if (! $store->updateRecord($site, $type, ['revoked_at' => now()->toIso8601String()])) {
            $this->error('Token not found or the token store is not a writable JSON file.');
            return self::FAILURE;
        }

        AuditLog::record('site_token.revoked', $site, ['token_type' => $type], 'cli');
        $this->info("Revoked {$type} token for {$site}.");

        return self::SUCCESS;
    }
}
