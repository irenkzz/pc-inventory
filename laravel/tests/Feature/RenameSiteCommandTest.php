<?php

namespace Tests\Feature;

use App\Console\Commands\RenameSite;
use App\Models\AuditLog;
use App\Models\CollectorSite;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Runner\CommandQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RenameSiteCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMockingConsoleOutput();
        $this->tokenFile = tempnam(sys_get_temp_dir(), 'tok');
        Config::set('inventory.site_tokens_file', $this->tokenFile);
        Config::set('inventory.site_tokens_json', '');
        file_put_contents($this->tokenFile, json_encode([
            'SITE-OLD' => [
                'collector' => ['token' => 'secret-coll', 'expires_at' => '2030-01-01T00:00:00Z', 'last_used_at' => '2026-01-01T00:00:00Z', 'last_used_ip' => '10.0.0.5'],
                'direct_runner' => ['token' => 'secret-dir', 'revoked_at' => '2026-02-02T00:00:00Z'],
            ],
            'SITE-OTHER' => 'secret-other',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->tokenFile);
        parent::tearDown();
    }

    private function seedSite(string $id = 'SITE-OLD'): void
    {
        CollectorSite::query()->create(['site_id' => $id, 'site_name' => 'Keep Name']);
        DB::table('collectors')->insert(['site_id' => $id, 'collector_name' => 'C1']);
        Runner::query()->create(['runner_id' => 'PC-1', 'hostname' => 'PC-1', 'site_id' => $id]);
        app(CommandQueueService::class)->queue('PC-1', $id, 'scan_now', 'test');
    }

    private function run_(array $args): array
    {
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        $code = Artisan::call('inventory:rename-site', $args, $buffer);

        return [$code, $buffer->fetch()];
    }

    private function counts(string $id): array
    {
        return array_map(fn ($t) => DB::table($t)->where('site_id', $id)->count(), RenameSite::TABLES);
    }

    public function test_dry_run_is_default_and_changes_nothing(): void
    {
        $this->seedSite();
        $before = file_get_contents($this->tokenFile);

        [$code, $out] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-NEW']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Would update', $out);
        $this->assertSame([1, 1, 1, 1], $this->counts('SITE-OLD'));
        $this->assertSame([0, 0, 0, 0], $this->counts('SITE-NEW'));
        $this->assertSame($before, file_get_contents($this->tokenFile));

        [$code] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-NEW', '--force' => true, '--dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertSame([1, 1, 1, 1], $this->counts('SITE-OLD'));
    }

    public function test_force_renames_all_tables_and_tokens_without_leaking_secrets(): void
    {
        $this->seedSite();

        [$code, $out] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-NEW', '--force' => true]);

        $this->assertSame(0, $code);
        $this->assertSame([1, 1, 1, 1], $this->counts('SITE-NEW'));
        $this->assertSame([0, 0, 0, 0], $this->counts('SITE-OLD'));
        $this->assertSame('Keep Name', DB::table('collector_sites')->where('site_id', 'SITE-NEW')->value('site_name'));

        $raw = json_decode(file_get_contents($this->tokenFile), true);
        $this->assertArrayNotHasKey('SITE-OLD', $raw);
        $this->assertSame('secret-coll', $raw['SITE-NEW']['collector']['token']);
        $this->assertSame('2030-01-01T00:00:00Z', $raw['SITE-NEW']['collector']['expires_at']);
        $this->assertSame('2026-01-01T00:00:00Z', $raw['SITE-NEW']['collector']['last_used_at']);
        $this->assertSame('10.0.0.5', $raw['SITE-NEW']['collector']['last_used_ip']);
        $this->assertSame('2026-02-02T00:00:00Z', $raw['SITE-NEW']['direct_runner']['revoked_at']);
        $this->assertSame('secret-other', $raw['SITE-OTHER']);

        foreach (['secret-coll', 'secret-dir', 'secret-other'] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }
        $this->assertStringContainsString('checklist', $out);

        $audit = AuditLog::query()->where('action', 'site.renamed')->firstOrFail();
        $this->assertSame('cli', $audit->actor);
        $this->assertSame('SITE-OLD->SITE-NEW', $audit->subject);
        $this->assertSame(2, $audit->details['token_records']);
        $this->assertStringNotContainsString('secret', json_encode($audit->details));
    }

    public function test_list_shaped_token_records_are_renamed(): void
    {
        file_put_contents($this->tokenFile, json_encode([
            ['site_id' => 'SITE-OLD', 'token' => 'secret-l', 'token_type' => 'direct_runner', 'expires_at' => '2030-01-01'],
        ]));

        [$code] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-NEW', '--force' => true]);

        $this->assertSame(0, $code);
        $raw = json_decode(file_get_contents($this->tokenFile), true);
        $this->assertSame('SITE-NEW', $raw[0]['site_id']);
        $this->assertSame('2030-01-01', $raw[0]['expires_at']);
    }

    public function test_refuses_invalid_unknown_and_conflicting_ids(): void
    {
        $this->seedSite();

        [$code] = $this->run_(['old' => 'SITE-OLD', 'new' => 'site-new', '--force' => true]);
        $this->assertSame(1, $code);

        [$code] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-OLD', '--force' => true]);
        $this->assertSame(1, $code);

        [$code] = $this->run_(['old' => 'SITE-NOPE', 'new' => 'SITE-NEW', '--force' => true]);
        $this->assertSame(1, $code);

        // new exists in tokens only
        [$code] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-OTHER', '--force' => true]);
        $this->assertSame(1, $code);

        // new exists in DB only
        CollectorSite::query()->create(['site_id' => 'SITE-DB', 'site_name' => 'x']);
        [$code] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-DB', '--force' => true]);
        $this->assertSame(1, $code);

        $this->assertSame([1, 1, 1, 1], $this->counts('SITE-OLD'));
    }

    public function test_env_based_store_requires_skip_tokens(): void
    {
        $this->seedSite();
        Config::set('inventory.site_tokens_json', json_encode(['SITE-OLD' => 'secret-env']));

        [$code, $out] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-NEW', '--force' => true]);
        $this->assertSame(1, $code);
        $this->assertSame([1, 1, 1, 1], $this->counts('SITE-OLD'));
        $this->assertStringNotContainsString('secret-env', $out);

        [$code] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-NEW', '--force' => true, '--skip-tokens' => true]);
        $this->assertSame(0, $code);
        $this->assertSame([1, 1, 1, 1], $this->counts('SITE-NEW'));
    }

    public function test_db_failure_rolls_back_everything(): void
    {
        $this->seedSite();
        $before = file_get_contents($this->tokenFile);
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'update "runner_commands"')) {
                throw new \RuntimeException('boom');
            }
        });

        [$code] = $this->run_(['old' => 'SITE-OLD', 'new' => 'SITE-NEW', '--force' => true]);

        $this->assertSame(1, $code);
        $this->assertSame([1, 1, 1, 1], $this->counts('SITE-OLD'));
        $this->assertSame([0, 0, 0, 0], $this->counts('SITE-NEW'));
        $this->assertSame($before, file_get_contents($this->tokenFile));
        $this->assertSame(0, AuditLog::query()->where('action', 'site.renamed')->count());
    }

    public function test_every_table_with_site_id_column_is_covered(): void
    {
        $tables = array_column(DB::select("select name from sqlite_master where type = 'table'"), 'name');
        $withSiteId = array_values(array_filter($tables, fn ($t) => Schema::hasColumn($t, 'site_id')));
        sort($withSiteId);
        $covered = RenameSite::TABLES;
        sort($covered);

        $this->assertSame($covered, $withSiteId, 'A table with a site_id column is not handled by inventory:rename-site.');
    }
}
