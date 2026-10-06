<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class RunnerUpdateSigningTest extends TestCase
{
    use RefreshDatabase;

    private string $keyDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyDir = storage_path('framework/testing/signing-keys-' . bin2hex(random_bytes(3)));
        Config::set('inventory.update_signing_key_path', $this->keyDir . DIRECTORY_SEPARATOR . 'update-signing-private.pem');
        Config::set('inventory.downloads_path', 'framework/testing/site-kit-signing');
        File::deleteDirectory(storage_path('app/framework/testing/site-kit-signing'));
        $this->withoutMockingConsoleOutput();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->keyDir);
        File::deleteDirectory(storage_path('app/framework/testing/site-kit-signing'));
        parent::tearDown();
    }

    private function run_command(string $command, array $args = []): array
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call($command, $args, $buffer);

        return [$code, $buffer->fetch()];
    }

    private function build(): array
    {
        return $this->run_command('inventory:build-site-kit', [
            '--profile' => 'deployment/profiles/site_hq.sample.json',
            '--site-token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            '--server-base-url' => 'https://inventory.example.local',
        ]);
    }

    public function test_keygen_writes_keys_never_prints_private_and_refuses_overwrite(): void
    {
        [$code, $output] = $this->run_command('inventory:update-keygen');

        $this->assertSame(0, $code);
        $private = $this->keyDir . DIRECTORY_SEPARATOR . 'update-signing-private.pem';
        $this->assertFileExists($private);
        $this->assertFileExists($this->keyDir . DIRECTORY_SEPARATOR . 'update-signing-public.xml');
        $this->assertStringContainsString('<RSAKeyValue><Modulus>', $output);
        $this->assertStringNotContainsString('PRIVATE KEY', $output);
        $this->assertStringNotContainsString(trim(explode("\n", (string) file_get_contents($private))[1]), $output);
        $this->assertSame(3072, openssl_pkey_get_details(openssl_pkey_get_private((string) file_get_contents($private)))['bits']);

        $before = file_get_contents($private);
        [$code2] = $this->run_command('inventory:update-keygen');
        $this->assertSame(1, $code2);
        $this->assertSame($before, file_get_contents($private));

        [$code3] = $this->run_command('inventory:update-keygen', ['--force' => true]);
        $this->assertSame(0, $code3);
        $this->assertNotSame($before, file_get_contents($private));
    }

    public function test_build_with_key_signs_manifest_and_ships_public_key(): void
    {
        $this->run_command('inventory:update-keygen');
        [$code, $output] = $this->build();
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('UNSIGNED', $output);

        $kit = storage_path('app/framework/testing/site-kit-signing/site-kit-SITE-HQ');
        $manifestPath = $kit . '/runner/manifest/runner-manifest.json';
        $manifestBytes = (string) file_get_contents($manifestPath);
        $manifest = json_decode($manifestBytes, true);
        $this->assertTrue($manifest['signed']);
        $this->assertSame('current', $manifest['package_folder']);
        $this->assertNotEmpty($manifest['files']);

        $paths = array_column($manifest['files'], 'path');
        $this->assertContains('config/update-public-key.xml', $paths);
        $this->assertContains('scripts/runner_main.ps1', $paths);
        $this->assertNotContains('manifest/runner-manifest.json', $paths);
        $this->assertNotContains('manifest/runner-manifest.sig', $paths);
        foreach ($manifest['files'] as $entry) {
            $this->assertSame(hash_file('sha256', $kit . '/runner/' . $entry['path']), $entry['sha256']);
            $this->assertSame(filesize($kit . '/runner/' . $entry['path']), $entry['size']);
        }

        $sig = base64_decode((string) file_get_contents($kit . '/runner/manifest/runner-manifest.sig'), true);
        $public = openssl_pkey_get_public(self::publicPem((string) file_get_contents($this->keyDir . '/update-signing-public.xml')));
        $this->assertSame(1, openssl_verify($manifestBytes, $sig, $public, OPENSSL_ALGO_SHA256));
        $this->assertSame(0, openssl_verify($manifestBytes . ' ', $sig, $public, OPENSSL_ALGO_SHA256));

        $template = json_decode((string) file_get_contents($kit . '/runner/config/runner-config.template.json'), true);
        $this->assertTrue($template['requireSignedUpdates']);
        $this->assertFileExists($kit . '/branch-share/packages/runner/current/manifest/runner-manifest.sig');
        $this->assertSame($manifestBytes, file_get_contents($kit . '/branch-share/packages/runner/runner-manifest.json'));
    }

    public function test_build_without_key_succeeds_unsigned_with_warning(): void
    {
        [$code, $output] = $this->build();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('WARNING: no update signing key', $output);
        $kit = storage_path('app/framework/testing/site-kit-signing/site-kit-SITE-HQ');
        $manifest = json_decode((string) file_get_contents($kit . '/runner/manifest/runner-manifest.json'), true);
        $this->assertFalse($manifest['signed']);
        $this->assertFileDoesNotExist($kit . '/runner/manifest/runner-manifest.sig');
        $this->assertFileDoesNotExist($kit . '/runner/config/update-public-key.xml');
        $template = json_decode((string) file_get_contents($kit . '/runner/config/runner-config.template.json'), true);
        $this->assertArrayNotHasKey('requireSignedUpdates', $template);
    }

    /** Convert .NET RSA XML to a PEM public key (minimal DER builder) so openssl_verify can check it. */
    private static function publicPem(string $xml): string
    {
        preg_match('#<Modulus>(.*?)</Modulus><Exponent>(.*?)</Exponent>#', $xml, $m);
        $int = function (string $bin): string {
            $bin = ltrim($bin, "\x00");
            if (ord($bin[0]) > 0x7f) {
                $bin = "\x00" . $bin;
            }

            return "\x02" . self::len(strlen($bin)) . $bin;
        };
        $seq = fn (string $c): string => "\x30" . self::len(strlen($c)) . $c;
        $rsaKey = $seq($int(base64_decode($m[1])) . $int(base64_decode($m[2])));
        $algo = hex2bin('300d06092a864886f70d0101010500');
        $bits = "\x03" . self::len(strlen($rsaKey) + 1) . "\x00" . $rsaKey;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($seq($algo . $bits)), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\x00");

        return chr(0x80 | strlen($b)) . $b;
    }
}
