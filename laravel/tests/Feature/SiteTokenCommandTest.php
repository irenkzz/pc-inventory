<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SiteTokenCommandTest extends TestCase
{
    public function test_direct_runner_token_registration_stores_typed_token_without_printing_secret_by_default(): void
    {
        $tokenFile = $this->tokenFile('register_direct_runner_tokens.json');
        Config::set('inventory.site_tokens_file', $tokenFile);

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:register-site-token', [
            'site_id' => 'SITE-HQ',
            '--type' => 'direct_runner',
            '--site-token' => 'direct-runner-secret',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"token_type": "direct_runner"', $output);
        $this->assertStringNotContainsString('direct-runner-secret', $output);

        $tokens = json_decode((string) file_get_contents($tokenFile), true);
        $this->assertSame('direct-runner-secret', $tokens['SITE-HQ']['direct_runner']);
    }

    public function test_register_site_token_reveals_secret_only_when_requested(): void
    {
        $tokenFile = $this->tokenFile('register_reveal_tokens.json');
        Config::set('inventory.site_tokens_file', $tokenFile);

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:register-site-token', [
            'site_id' => 'SITE-HQ',
            '--type' => 'direct_runner',
            '--site-token' => 'direct-runner-secret',
            '--reveal' => true,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('direct-runner-secret', $output);
    }

    public function test_collector_registration_and_listing_preserve_legacy_behavior_with_masking(): void
    {
        $tokenFile = $this->tokenFile('register_collector_tokens.json');
        Config::set('inventory.site_tokens_file', $tokenFile);

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:register-site-token', [
            'site_id' => 'SITE-HQ',
            '--site-token' => 'collector-secret-1234',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"token_type": "collector"', $output);
        $this->assertStringNotContainsString('collector-secret-1234', $output);

        $tokens = json_decode((string) file_get_contents($tokenFile), true);
        $this->assertSame('collector-secret-1234', $tokens['SITE-HQ']);

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:site-tokens', [], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SITE-HQ', $output);
        $this->assertStringContainsString('collector', $output);
        $this->assertStringContainsString('coll*************1234', $output);
        $this->assertStringNotContainsString('collector-secret-1234', $output);
    }

    public function test_listing_direct_and_all_tokens_masks_by_default(): void
    {
        $tokenFile = $this->tokenFile('list_typed_tokens.json');
        file_put_contents($tokenFile, json_encode([
            'SITE-HQ' => [
                'collector' => 'collector-secret-1234',
                'direct_runner' => 'direct-runner-secret',
            ],
        ]));
        Config::set('inventory.site_tokens_file', $tokenFile);

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:site-tokens', [
            '--type' => 'direct_runner',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SITE-HQ', $output);
        $this->assertStringContainsString('direct_runner', $output);
        $this->assertStringContainsString('dire************cret', $output);
        $this->assertStringNotContainsString('direct-runner-secret', $output);
        $this->assertStringNotContainsString('collector-secret-1234', $output);

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:site-tokens', [
            '--type' => 'all',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('collector', $output);
        $this->assertStringContainsString('direct_runner', $output);
        $this->assertStringContainsString('coll*************1234', $output);
        $this->assertStringContainsString('dire************cret', $output);
        $this->assertStringNotContainsString('collector-secret-1234', $output);
        $this->assertStringNotContainsString('direct-runner-secret', $output);
    }

    private function tokenFile(string $name): string
    {
        $path = storage_path('framework/testing/' . $name);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        if (is_file($path)) {
            unlink($path);
        }

        return $path;
    }
}
