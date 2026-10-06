<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\File;

/** Offline-key signing for runner update packages. Private key never leaves the build host. */
class UpdateSigner
{
    public function privateKeyPath(): string
    {
        $path = (string) config('inventory.update_signing_key_path');
        $absolute = preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $path) === 1;

        return $absolute ? $path : storage_path(ltrim($path, '/\\'));
    }

    public function publicKeyPath(): string
    {
        return dirname($this->privateKeyPath()) . DIRECTORY_SEPARATOR . 'update-signing-public.xml';
    }

    public function hasKey(): bool
    {
        return is_file($this->privateKeyPath()) && is_file($this->publicKeyPath());
    }

    /** Returns the public key as .NET RSA XML. */
    public function generate(): string
    {
        $opts = ['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = @openssl_pkey_new($opts);
        if ($key === false) {
            // Windows PHP often ships without openssl.cnf; retry with a minimal config file.
            $cnf = tempnam(sys_get_temp_dir(), 'ossl');
            file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n");
            $opts['config'] = $cnf;
            $key = openssl_pkey_new($opts);
        }
        try {
            if ($key === false || ! openssl_pkey_export($key, $pem, null, isset($cnf) ? ['config' => $cnf] : [])) {
                throw new \RuntimeException('OpenSSL could not generate the RSA key: ' . (openssl_error_string() ?: 'unknown error'));
            }
        } finally {
            if (isset($cnf)) {
                @unlink($cnf);
            }
        }
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $xml = '<RSAKeyValue><Modulus>' . base64_encode($rsa['n']) . '</Modulus><Exponent>' . base64_encode($rsa['e']) . '</Exponent></RSAKeyValue>';

        $private = $this->privateKeyPath();
        File::ensureDirectoryExists(dirname($private));
        File::put($private, $pem);
        @chmod($private, 0600);
        File::put($this->publicKeyPath(), $xml);

        return $xml;
    }

    public function publicKeyXml(): string
    {
        return trim((string) file_get_contents($this->publicKeyPath()));
    }

    /** Base64 RSA-SHA256 (PKCS1 v1.5) signature over the exact bytes. */
    public function sign(string $data): string
    {
        $key = openssl_pkey_get_private((string) file_get_contents($this->privateKeyPath()));
        if ($key === false || ! openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign with the update signing key.');
        }

        return base64_encode($signature);
    }
}
