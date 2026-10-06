<?php

namespace App\Services\Security;

use Illuminate\Http\Request;

class DirectRunnerAuthService
{
    public function __construct(
        private readonly SiteTokenVerifier $verifier,
        private readonly SiteTokenStore $tokens,
    ) {
    }

    public function authenticate(Request $request): array
    {
        $siteCode = $this->siteCode($request);
        abort_if($siteCode === '', 401);

        $authRequest = $this->requestWithSiteId($request, $siteCode);
        $verifiedSite = $this->verifier->verify($authRequest, SiteTokenStore::TYPE_DIRECT_RUNNER);
        abort_if($verifiedSite !== $siteCode, 401);

        $token = $this->token($authRequest);
        $record = collect($this->tokens->records(SiteTokenStore::TYPE_DIRECT_RUNNER))->first(
            fn (array $record): bool => $record['site_id'] === $verifiedSite
                && $record['revoked_at'] === null
                && hash_equals($record['token'], $token),
        );

        abort_if($record === null, 401);

        return [
            'site_code' => $verifiedSite,
            'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
            'token_metadata' => [
                'last_used_at' => $record['last_used_at'],
                'last_used_ip' => $record['last_used_ip'],
                'revoked_at' => $record['revoked_at'],
            ],
        ];
    }

    private function siteCode(Request $request): string
    {
        $values = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): string => trim((string) $value),
            [
                $request->header('X-Site-Id'),
                $request->query('site_id'),
                $request->input('site_id'),
                $request->query('site_code'),
                $request->input('site_code'),
            ],
        ))));

        abort_if(count($values) > 1, 401);

        return $values[0] ?? '';
    }

    private function requestWithSiteId(Request $request, string $siteCode): Request
    {
        $query = $request->query->all();
        $query['site_id'] = $siteCode;

        return $request->duplicate(
            $query,
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
        );
    }

    private function token(Request $request): string
    {
        return trim((string) ($request->header('X-Site-Token')
            ?: (config('inventory.allow_query_site_token', true) ? $request->query('site_token', '') : '')));
    }
}
