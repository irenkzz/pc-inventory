<?php

namespace Tests\Feature;

use App\Models\ClassificationRule;
use App\Models\Device;
use App\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ViewerExportAuditTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::query()->forceCreate([
            'name' => $role, 'email' => "$role@x.test", 'password' => bcrypt('pw'), 'role' => $role,
        ]);
    }

    public function test_viewer_does_not_see_mutating_forms_but_admin_does(): void
    {
        $runner = Runner::query()->create(['runner_id' => 'RUN-1', 'hostname' => 'h']);
        $rule = ClassificationRule::query()->firstOrCreate(
            ['rule_type' => ClassificationRule::TYPE_ASSET_PREFIX_DEPARTMENT, 'match_value' => 'BRT'],
            ['output_value' => 'News', 'priority' => 100, 'is_active' => true],
        );
        $actions = [
            '/runners' => [route('admin.runners.manual-scan', $runner, false)],
            '/classification-rules' => [
                route('admin.classification-rules.apply', [], false),
                route('admin.classification-rules.update', $rule, false),
                route('admin.classification-rules.destroy', $rule, false),
            ],
        ];

        $viewerUser = $this->user('viewer');
        foreach ($actions as $page => $urls) {
            $viewer = $this->actingAs($viewerUser)->get($page)->assertOk();
            foreach ($urls as $u) {
                $viewer->assertDontSee('action="' . url($u) . '"', false);
            }
            $this->flushSession();
            $admin = $this->actingAs(User::query()->forceCreate([
                'name' => 'a' . $page, 'email' => "a$page@x.test", 'password' => bcrypt('pw'), 'role' => 'admin',
            ]))->get($page)->assertOk();
            foreach ($urls as $u) {
                $admin->assertSee('action="' . url($u) . '"', false);
            }
        }
    }

    public function test_exports_require_auth_and_viewer_can_download_csv_with_escaping(): void
    {
        $this->get('/devices/export')->assertRedirect();
        $this->get('/runners/export')->assertRedirect();

        $now = now()->toDateTimeString();
        Device::query()->create([
            'device_uid' => 'u1', 'first_seen_at' => $now, 'last_seen_at' => $now,
            'current_asset_code' => 'PC-1', 'current_user_name' => '=HYPERLINK("http://evil")',
        ]);
        Runner::query()->create(['runner_id' => 'RUN-9', 'hostname' => '@cmd', 'raw_state_json' => ['secret' => 'tok123']]);

        $this->actingAs($this->user('viewer'));
        $dev = $this->get('/devices/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $dev->streamedContent();
        $this->assertStringStartsWith('asset_code,site,', $body);
        $this->assertStringContainsString('PC-1', $body);
        $this->assertStringContainsString("\"'=HYPERLINK(", $body);

        $run = $this->get('/runners/export')->assertOk()->streamedContent();
        $this->assertStringStartsWith('runner_id,hostname,', $run);
        $this->assertStringContainsString("RUN-9,'@cmd", $run);
        $this->assertStringNotContainsString('tok123', $run);
    }

    public function test_prune_audit_log_by_retention(): void
    {
        $old = now()->subDays(800)->toDateTimeString();
        DB::table('audit_log')->insert([
            ['actor' => 'x', 'action' => 'old', 'created_at' => $old],
            ['actor' => 'x', 'action' => 'new', 'created_at' => now()->toDateTimeString()],
        ]);

        $this->artisan('inventory:prune')->assertSuccessful();
        $this->assertSame(2, DB::table('audit_log')->count());

        $this->artisan('inventory:prune', ['--force' => true])->assertSuccessful();
        $this->assertSame(['new'], DB::table('audit_log')->pluck('action')->all());

        $this->artisan('inventory:prune', ['--force' => true, '--audit-log-days' => 0])->assertFailed();
        $this->artisan('inventory:prune', ['--force' => true, '--audit-log-days' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('audit_log')->count());
    }
}
