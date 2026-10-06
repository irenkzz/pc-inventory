<?php

namespace App\Services\Security;

use Carbon\Carbon;
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
        $token = trim((string) ($request->header('X-Site-Token')
            ?: (config('inventory.allow_query_site_token', true) ? $request->query('site_token', '') : '')));
        $records = $this->tokens->records();

        if ($records === []) {
            abort_if((bool) config('inventory.require_site_tokens'), 401);

            return $siteId;
        }

        $matchingRecord = collect($records)->first(
            fn (array $record): bool => $record['site_id'] === $siteId
                && $record['token_type'] === $requiredType
                && $record['revoked_at'] === null
                && ! $this->isExpired($record['expires_at'])
                && hash_equals($record['token'], $token),
        );

        abort_if($siteId === '' || $matchingRecord === null, 401);

        $this->recordUse($matchingRecord, $request);

        return $siteId;
    }

    private function isExpired(?string $expiresAt): bool
    {
        if ($expiresAt === null) {
            return false;
        }

        try {
            return Carbon::parse($expiresAt)->lte(now());
        } catch (\Throwable) {
            return true; // unparseable expiry fails closed
        }
    }

    /** Throttled last-used bookkeeping; never fails the request. */
    private function recordUse(array $record, Request $request): void
    {
        try {
            $last = $record['last_used_at'];
            if ($last !== null && Carbon::parse($last)->gt(now()->subMinutes(5))) {
                return;
            }

            $this->tokens->updateRecord($record['site_id'], $record['token_type'], [
                'last_used_at' => now()->toIso8601String(),
                'last_used_ip' => $request->ip(),
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }
}
