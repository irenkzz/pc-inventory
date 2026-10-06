<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiRateLimitConfigTest extends TestCase
{
    public function test_api_limit_defaults_to_60_per_minute(): void
    {
        $this->assertSame(60, config('inventory.api_rate_limit_per_minute'));
    }

    public function test_api_limit_follows_the_config_value(): void
    {
        Config::set('inventory.api_rate_limit_per_minute', 2);
        RateLimiter::clear('api');

        $statuses = [];
        for ($i = 0; $i < 3; $i++) {
            $statuses[] = $this->postJson('/api/collector/status', [])->getStatusCode();
        }

        $this->assertNotSame(429, $statuses[0]);
        $this->assertNotSame(429, $statuses[1]);
        $this->assertSame(429, $statuses[2]);
    }
}
