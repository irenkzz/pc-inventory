<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedLoopbackProxyTest extends TestCase
{
    public function test_client_ip_comes_from_forwarded_header_when_proxy_is_loopback(): void
    {
        Route::get('/_test/ip', fn (Request $request) => $request->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
            ->get('/_test/ip')
            ->assertOk()
            ->assertSeeText('203.0.113.7');
    }

    public function test_forwarded_header_is_ignored_for_untrusted_peers(): void
    {
        Route::get('/_test/ip', fn (Request $request) => $request->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
            ->get('/_test/ip')
            ->assertOk()
            ->assertSeeText('198.51.100.9');
    }
}
