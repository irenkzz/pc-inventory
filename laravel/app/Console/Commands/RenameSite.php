<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Deployment\SiteKitProfileValidator;
use App\Services\Security\SiteTokenStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RenameSite extends Command
{
    /** Every table with an exact site_id column (guarded by a test). collector_sites is the FK parent. */
    public const TABLES = ['collector_sites', 'collectors', 'runners', 'runner_commands'];

    protected $signature = 'inventory:rename-site {old} {new}
        {--force : Execute the rename (default is dry-run)}
        {--dry-run : Report only; wins over --force}
        {--skip-tokens : Do not touch the token store (required when tokens come from env)}';

    protected $description = 'Rename a site id in the database and token store (dry-run unless --force)';

    public function handle(SiteTokenStore $tokens): int
    {
        $old = (string) $this->argument('old');
        $new = (string) $this->argument('new');
        $execute = (bool) $this->option('force') && ! $this->option('dry-run');
        $skipTokens = (bool) $this->option('skip-tokens');

        if (! SiteKitProfileValidator::isValidSiteId($new)) {
            return $this->fail('New site id should use uppercase letters, numbers, underscore, or dash.');
        }
        if ($old === $new) {
            return $this->fail('Old and new site ids are identical.');
        }

        $oldCounts = $this->counts($old);
        $oldInTokens = ! $skipTokens && $tokens->hasSite($old);
        if (array_sum($oldCounts) === 0 && ! $oldInTokens) {
            return $this->fail("Site {$old} not found.");
        }
        if (array_sum($this->counts($new)) > 0 || $tokens->hasSite($new)) {
            return $this->fail("Site {$new} already exists; merging is not supported.");
        }
        if (! $skipTokens && $tokens->isReadOnly()) {
            return $this->fail('Token store is env-based (site_tokens_json) and read-only. Rename the token key manually in the env and re-run with --skip-tokens.');
        }

        $tokenCount = $skipTokens ? 0 : count(array_filter($tokens->records(), fn (array $r): bool => $r['site_id'] === $old));

        if (! $execute) {
            $this->renderTable('Would update', $oldCounts, $tokenCount);
            $this->warn('Dry run: nothing changed. Back up first, then re-run with --force.');

            return self::SUCCESS;
        }

        try {
            $updated = DB::transaction(fn (): array => $this->renameRows($old, $new));
        } catch (\Throwable $e) {
            return $this->fail('Database rename failed, nothing changed: ' . $e->getMessage());
        }

        $tokenError = null;
        if (! $skipTokens) {
            try {
                $tokenCount = $tokens->renameSite($old, $new);
            } catch (\Throwable $e) {
                $tokenError = $e->getMessage();
            }
        }

        AuditLog::record('site.renamed', "{$old}->{$new}", [
            'rows' => $updated,
            'token_records' => $tokenError === null ? $tokenCount : 0,
            'tokens_skipped' => $skipTokens,
            'token_error' => $tokenError !== null,
        ], 'cli');

        $this->renderTable('Updated', $updated, $tokenError === null ? $tokenCount : 0);

        if ($tokenError !== null) {
            $this->error("Database renamed, but token store rename FAILED ({$tokenError}).");
            $this->error("Manual fix: in {$tokens->source()} rename the site key/site_id \"{$old}\" to \"{$new}\" for every token type.");

            return self::FAILURE;
        }
        if ($skipTokens) {
            $this->warn("Tokens skipped: rename the token key \"{$old}\" to \"{$new}\" manually.");
        }

        $this->checklist($old, $new);

        return self::SUCCESS;
    }

    private function counts(string $siteId): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->where('site_id', $siteId)->count();
        }

        return $counts;
    }

    private function renameRows(string $old, string $new): array
    {
        $updated = array_fill_keys(self::TABLES, 0);

        // collector_sites is the FK parent: create the new row, move children, drop the old row.
        $site = DB::table('collector_sites')->where('site_id', $old)->first();
        if ($site !== null) {
            DB::table('collector_sites')->insert(['site_id' => $new] + (array) $site);
            $updated['collector_sites'] = 1;
        }
        foreach (array_slice(self::TABLES, 1) as $table) {
            $updated[$table] = DB::table($table)->where('site_id', $old)->update(['site_id' => $new]);
        }
        DB::table('collector_sites')->where('site_id', $old)->delete();

        return $updated;
    }

    private function renderTable(string $label, array $counts, int $tokenCount): void
    {
        $rows = [];
        foreach ($counts as $table => $n) {
            $rows[] = [$table, $n];
        }
        $rows[] = ['token records', $tokenCount];
        $this->table(['Table', $label], $rows);
    }

    private function checklist(string $old, string $new): void
    {
        $this->line('');
        $this->line('Follow-up checklist:');
        $this->line("  1. Collector config: site_id -> {$new} and X-Site-Id/token header.");
        $this->line("  2. Branch share folder: ...\\sites\\{$old} -> ...\\sites\\{$new}");
        $this->line("  3. Every runner config: siteId -> {$new} (and token).");
        $this->line("  4. Rebuild site kits with profiles using site_id {$new}; kits for {$old} are now invalid.");
        $this->warn("Runners still using {$old} get 401 until updated.");
    }

    private function fail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
