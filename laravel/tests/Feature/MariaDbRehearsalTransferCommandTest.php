<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class MariaDbRehearsalTransferCommandTest extends TestCase
{
    public function test_command_is_registered_and_has_no_execute_option(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('inventory:mariadb-rehearsal-transfer', $commands);
        $this->assertTrue($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption('source'));
        $this->assertTrue($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption('dry-run'));
        $this->assertFalse($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption('execute'));
    }

    public function test_command_requires_dry_run(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] --dry-run is required. This command is dry-run-only.', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_command_requires_explicit_source(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] --source is required and must be explicit.', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_command_refuses_live_sqlite_path(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\database\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Source path is the live SQLite database and is refused.', $output);
        $this->assertStringContainsString('Source path is under the live Laravel path and is refused.', $output);
    }

    public function test_command_refuses_source_under_live_laravel_path(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\storage\\copy.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Source path is under the live Laravel path and is refused.', $output);
    }

    public function test_command_refuses_wrong_base_path(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Current Laravel base path is not the rehearsal path.', $output);
    }

    public function test_command_refuses_wrong_database_name(): void
    {
        $this->app->setBasePath('D:\\inventory-rehearsal\\laravel');
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'not_inventory_rehearsal');

        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('DB_DATABASE is not inventory_rehearsal', $output);
    }

    public function test_command_refuses_live_app_url(): void
    {
        $this->app->setBasePath('D:\\inventory-rehearsal\\laravel');
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'inventory_rehearsal');
        Config::set('app.url', 'https://inventory-pilot.internal.lan');

        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('APP_URL contains inventory-pilot.internal.lan', $output);
    }

    public function test_command_checks_source_exists_after_boundaries_pass(): void
    {
        $this->setRehearsalBoundaryConfig();

        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Source SQLite file does not exist.', $output);
    }

    public function test_command_prints_required_dry_run_sections_on_boundary_failures(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\database\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Environment boundary', $output);
        $this->assertStringContainsString('Result', $output);
        $this->assertStringContainsString('Dry-run only. No execute mode exists. No data is written.', $output);
    }

    public function test_command_does_not_print_secrets_or_payloads(): void
    {
        Config::set('app.key', 'base64:fake-app-key-secret-value');
        Config::set('database.connections.mysql.password', 'fake-db-password-secret-value');

        [, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\database\\database.sqlite',
        ]);

        $this->assertStringNotContainsString('fake-app-key-secret-value', $output);
        $this->assertStringNotContainsString('fake-db-password-secret-value', $output);
        $this->assertStringNotContainsString('payload_json', $output);
        $this->assertStringNotContainsString('command payload', strtolower($output));
    }

    public function test_command_result_line_is_exactly_one_final_line(): void
    {
        [, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\database\\database.sqlite',
        ]);

        $this->assertSame(1, preg_match_all('/^Result: (PASS|WARN|FAIL)\r?$/m', $output));
        $this->assertMatchesRegularExpression('/Result: (PASS|WARN|FAIL)\s*$/', $output);
    }

    public function test_command_class_contains_planned_order_and_no_write_mode(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MariaDbRehearsalTransfer.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('users', $source);
        $this->assertStringContainsString('runner_commands', $source);
        $this->assertStringContainsString('Do not transfer migrations from SQLite', $source);
        $this->assertStringNotContainsString("'{--execute", $source);
        $this->assertStringNotContainsString('truncate(', $source);
        $this->assertStringNotContainsString('delete(', $source);
        $this->assertStringNotContainsString('insert(', $source);
        $this->assertStringNotContainsString('update(', $source);
    }

    private function setRehearsalBoundaryConfig(): void
    {
        $this->app->setBasePath('D:\\inventory-rehearsal\\laravel');
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'inventory_rehearsal');
        Config::set('app.url', 'http://127.0.0.1:8090');
    }

    private function runCommand(array $parameters): array
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:mariadb-rehearsal-transfer', $parameters, $buffer);

        return [$exitCode, $buffer->fetch()];
    }
}
