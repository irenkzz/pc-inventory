<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Security\SiteTokenStore;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RotateSiteToken extends Command
{
    protected $signature = 'inventory:rotate-site-token
        {site_id}
        {--type=collector : Token type: collector or direct_runner}
        {--reveal : Show the new token value in command output}';

    protected $description = 'Replace a site token with a new random one (old token stops working)';

    public function handle(SiteTokenStore $store): int
    {
        $type = trim((string) $this->option('type')) ?: SiteTokenStore::TYPE_COLLECTOR;
        $site = trim((string) $this->argument('site_id'));

        if (! in_array($type, [SiteTokenStore::TYPE_COLLECTOR, SiteTokenStore::TYPE_DIRECT_RUNNER], true)) {
            $this->error('type must be collector or direct_runner.');
            return self::FAILURE;
        }

        $token = Str::random(48);
        $ok = $store->updateRecord($site, $type, [
            'token' => $token,
            'revoked_at' => null,
            'expires_at' => null,
            'last_used_at' => null,
            'last_used_ip' => null,
        ]);

        if (! $ok) {
            $this->error('Token not found or the token store is not a writable JSON file.');
            return self::FAILURE;
        }

        AuditLog::record('site_token.rotated', $site, ['token_type' => $type], 'cli');
        $this->info("Rotated {$type} token for {$site}. Regenerate site kits / update collectors and runners.");
        if ($this->option('reveal')) {
            $this->line($token);
        }

        return self::SUCCESS;
    }
}
