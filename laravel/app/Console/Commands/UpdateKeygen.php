<?php

namespace App\Console\Commands;

use App\Services\Deployment\UpdateSigner;
use Illuminate\Console\Command;

class UpdateKeygen extends Command
{
    protected $signature = 'inventory:update-keygen {--force : Overwrite an existing key pair (invalidates all signed kits already issued)}';

    protected $description = 'Generate the offline RSA-3072 runner update signing key (official build host only)';

    public function handle(UpdateSigner $signer): int
    {
        if (is_file($signer->privateKeyPath()) && ! $this->option('force')) {
            $this->error('A signing key already exists. Refusing to overwrite without --force.');

            return self::FAILURE;
        }

        try {
            $xml = $signer->generate();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Private key written (not shown): ' . $signer->privateKeyPath());
        $this->warn('Back up the private key offline. Anyone holding it can sign runner updates.');
        $this->info('Public key written: ' . $signer->publicKeyPath());
        $this->line($xml);

        return self::SUCCESS;
    }
}
