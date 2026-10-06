<?php

namespace App\Services\Security;

class SiteTokenStore
{
    public const TYPE_COLLECTOR = 'collector';
    public const TYPE_DIRECT_RUNNER = 'direct_runner';

    public function all(string $tokenType = self::TYPE_COLLECTOR): array
    {
        $tokens = [];
        foreach ($this->records($tokenType) as $record) {
            $tokens[$record['site_id']] = $record['token'];
        }

        return $tokens;
    }

    public function records(?string $tokenType = null): array
    {
        $records = $this->loadRecords();
        if ($tokenType === null) {
            return $records;
        }

        return array_values(array_filter(
            $records,
            fn (array $record): bool => $record['token_type'] === $tokenType,
        ));
    }

    public function hasTokens(): bool
    {
        return $this->records() !== [];
    }

    private function loadRecords(): array
    {
        $fromConfig = trim((string) config('inventory.site_tokens_json', ''));
        if ($fromConfig !== '') {
            $decoded = json_decode($fromConfig, true);
            if (is_array($decoded)) {
                return $this->normalizeRecords($decoded);
            }
        }

        $path = $this->filePath();
        if ($path !== '' && is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                return $this->normalizeRecords($decoded);
            }
        }

        return [];
    }

    /**
     * Set (or unset when null) fields on one record in the JSON file store.
     * Returns false when the store is env-based, the file is missing, or the record is not found.
     * Atomic: temp file + rename. Throws nothing the caller must handle except I/O (caller decides).
     */
    public function updateRecord(string $siteId, string $tokenType, array $fields): bool
    {
        if (trim((string) config('inventory.site_tokens_json', '')) !== '') {
            return false;
        }

        $path = $this->filePath();
        if ($path === '' || ! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            return false;
        }

        $found = false;
        $apply = function (mixed $entry) use ($fields, &$found): array {
            $found = true;
            $entry = is_array($entry) ? $entry : ['token' => (string) $entry];
            foreach ($fields as $key => $value) {
                if ($value === null) {
                    unset($entry[$key]);
                } else {
                    $entry[$key] = $value;
                }
            }

            return $entry;
        };

        foreach ($raw as $key => $value) {
            if (is_int($key) && is_array($value)) {
                if (trim((string) ($value['site_id'] ?? '')) === $siteId && $this->typeOf($value) === $tokenType) {
                    $raw[$key] = $apply($value);
                    break;
                }

                continue;
            }

            if (trim((string) $key) !== $siteId) {
                continue;
            }

            if (is_string($value)) {
                if ($tokenType === self::TYPE_COLLECTOR) {
                    $raw[$key] = $apply(['token' => $value, 'token_type' => $tokenType]);
                }
            } elseif (is_array($value) && array_is_list($value)) {
                foreach ($value as $i => $item) {
                    if (is_array($item) && $this->typeOf($item) === $tokenType) {
                        $raw[$key][$i] = $apply($item);
                        break;
                    }
                }
            } elseif (is_array($value) && array_key_exists('token', $value)) {
                if ($this->typeOf($value) === $tokenType) {
                    $raw[$key] = $apply($value);
                }
            } elseif (is_array($value) && isset($value[$tokenType])) {
                $raw[$key][$tokenType] = $apply($value[$tokenType]);
            }

            if ($found) {
                break;
            }
        }

        if (! $found) {
            return false;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || ! rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    public function isReadOnly(): bool
    {
        return trim((string) config('inventory.site_tokens_json', '')) !== '';
    }

    public function hasSite(string $siteId): bool
    {
        foreach ($this->records() as $record) {
            if ($record['site_id'] === $siteId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rename a site key for all token types in the JSON file store, keeping every record field.
     * Returns the number of token records renamed. Atomic: temp file + rename.
     *
     * @throws \RuntimeException when the store is env-based, missing, unwritable or the write fails
     */
    public function renameSite(string $old, string $new): int
    {
        $path = $this->filePath();
        if ($this->isReadOnly() || $path === '' || ! is_file($path) || ! is_writable($path)) {
            throw new \RuntimeException('Token store is not a writable JSON file.');
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            throw new \RuntimeException('Token file is not valid JSON.');
        }

        $count = count(array_filter($this->records(), fn (array $r): bool => $r['site_id'] === $old));
        if ($count === 0) {
            return 0;
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (is_int($key) && is_array($value)) {
                if (trim((string) ($value['site_id'] ?? '')) === $old) {
                    $value['site_id'] = $new;
                }
                $out[] = $value;
            } elseif (trim((string) $key) === $old) {
                $out[$new] = $value;
            } else {
                $out[$key] = $value;
            }
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || ! rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write token file.');
        }

        return $count;
    }

    private function typeOf(array $entry): string
    {
        return trim((string) ($entry['token_type'] ?? '')) ?: self::TYPE_COLLECTOR;
    }
    public function source(): string
    {
        if (trim((string) config('inventory.site_tokens_json', '')) !== '') {
            return 'inventory.site_tokens_json';
        }

        return $this->filePath();
    }

    public function filePath(): string
    {
        $path = trim((string) config('inventory.site_tokens_file'));
        if ($path === '' || $this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function normalizeRecords(array $tokens): array
    {
        $records = [];

        foreach ($tokens as $siteId => $value) {
            if (is_int($siteId) && is_array($value)) {
                $record = $this->normalizeRecord($value['site_id'] ?? '', $value);
                if ($record !== null) {
                    $records[] = $record;
                }

                continue;
            }

            foreach ($this->expandSiteValue((string) $siteId, $value) as $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function expandSiteValue(string $siteId, mixed $value): array
    {
        if (is_string($value)) {
            $record = $this->normalizeRecord($siteId, ['token' => $value]);

            return $record === null ? [] : [$record];
        }

        if (! is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return array_values(array_filter(array_map(
                fn (mixed $item): ?array => is_array($item) ? $this->normalizeRecord($siteId, $item) : null,
                $value,
            )));
        }

        if (array_key_exists('token', $value)) {
            $record = $this->normalizeRecord($siteId, $value);

            return $record === null ? [] : [$record];
        }

        $records = [];
        foreach ($value as $tokenType => $tokenValue) {
            $record = is_array($tokenValue)
                ? $this->normalizeRecord($siteId, ['token_type' => $tokenType] + $tokenValue)
                : $this->normalizeRecord($siteId, ['token_type' => $tokenType, 'token' => $tokenValue]);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function normalizeRecord(string $siteId, array $record): ?array
    {
        $siteId = trim($siteId);
        $token = trim((string) ($record['token'] ?? ''));
        $tokenType = $this->normalizeTokenType((string) ($record['token_type'] ?? self::TYPE_COLLECTOR));

        if ($siteId === '' || $token === '' || $tokenType === null) {
            return null;
        }

        return [
            'site_id' => $siteId,
            'token' => $token,
            'token_type' => $tokenType,
            'revoked_at' => $this->optionalString($record['revoked_at'] ?? null),
            'expires_at' => $this->optionalString($record['expires_at'] ?? null),
            'last_used_at' => $this->optionalString($record['last_used_at'] ?? null),
            'last_used_ip' => $this->optionalString($record['last_used_ip'] ?? null),
        ];
    }

    private function normalizeTokenType(string $tokenType): ?string
    {
        $tokenType = trim($tokenType);
        if ($tokenType === '') {
            return self::TYPE_COLLECTOR;
        }

        return in_array($tokenType, [self::TYPE_COLLECTOR, self::TYPE_DIRECT_RUNNER], true) ? $tokenType : null;
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
