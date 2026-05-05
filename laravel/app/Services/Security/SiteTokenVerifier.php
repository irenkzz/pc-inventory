<?php

namespace App\Services\Security;

use Illuminate\Http\Request;

class SiteTokenVerifier
{
    public function __construct(private readonly SiteTokenStore $tokens)
    {
    }

    public function verify(Request $request, string $requiredType = SiteTokenStore::TYPE_COLLECTOR): string
    {
        // Header auth is preferred; query-string fallback is kept for backward compatibility.
        $siteId = trim((string) ($request->header('X-Site-Id') ?: $request->query('site_id', '')));
        $token = trim((string) ($request->header('X-Site-Token') ?: $request->query('site_token', '')));
        $records = $this->tokens->records();

        if ($records === []) {
            abort_if((bool) config('inventory.require_site_tokens'), 401);

            return $siteId;
        }

        $matchingRecord = collect($records)->first(
            fn (array $record): bool => $record['site_id'] === $siteId
                && $record['token_type'] === $requiredType
                && $record['revoked_at'] === null
                && hash_equals($record['token'], $token),
        );

        abort_if($siteId === '' || $matchingRecord === null, 401);

        return $siteId;
    }
}
