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
