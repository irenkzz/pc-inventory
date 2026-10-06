<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ClassificationRule;
use App\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RbacAuditTest extends TestCase
{
    use RefreshDatabase;

    private function runner(): Runner
    {
        \App\Models\CollectorSite::query()->create(['site_id' => 'SITE-HQ', 'site_name' => 'HQ']);

        return Runner::query()->create(['runner_id' => 'R1', 'site_id' => 'SITE-HQ']);
    }

    public function test_default_role_is_admin_and_admin_can_queue_command_with_actor_recorded(): void
    {
        $user = User::factory()->create();
        $this->assertSame('admin', $user->fresh()->role);

        $runner = $this->runner();
        $this->actingAs($user)->post(route('admin.runners.manual-scan', $runner))->assertRedirect();

        $this->assertDatabaseHas('runner_commands', ['runner_id' => 'R1', 'requested_by' => $user->email]);
        $this->assertDatabaseHas('audit_log', ['actor' => $user->email, 'action' => 'command.queued', 'subject' => 'R1']);
    }

    public function test_viewer_can_read_but_not_mutate(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $runner = $this->runner();

        $this->actingAs($viewer)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.audit-log.index'))->assertOk();
        $this->actingAs($viewer)->post(route('admin.runners.manual-scan', $runner))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.classification-rules.apply'))->assertForbidden();
        $this->assertDatabaseCount('runner_commands', 0);
    }

    public function test_classification_rule_changes_are_audited_and_listed(): void
    {
        $admin = User::factory()->create();
        $rule = ClassificationRule::query()->create([
            'rule_type' => 'department', 'match_value' => 'AB', 'output_value' => 'Finance', 'priority' => 1, 'is_active' => true,
        ]);

        $this->actingAs($admin)->delete(route('admin.classification-rules.destroy', $rule))->assertRedirect();

        $this->assertDatabaseHas('audit_log', ['action' => 'classification_rule.deleted', 'actor' => $admin->email]);
        $this->actingAs($admin)->get(route('admin.audit-log.index'))->assertOk()->assertSee('classification_rule.deleted');
    }

    public function test_user_role_command_validates_and_audits(): void
    {
        $user = User::factory()->create();

        $this->assertSame(1, Artisan::call('inventory:user-role', ['email' => $user->email, 'role' => 'root']));
        $this->assertSame(1, Artisan::call('inventory:user-role', ['email' => 'nobody@example.test', 'role' => 'viewer']));
        $this->assertSame('admin', $user->fresh()->role);

        $this->assertSame(0, Artisan::call('inventory:user-role', ['email' => $user->email, 'role' => 'viewer']));
        $this->assertSame('viewer', $user->fresh()->role);
        $this->assertSame(1, AuditLog::query()->where(['action' => 'user.role_changed', 'actor' => 'cli'])->count());
    }
}
