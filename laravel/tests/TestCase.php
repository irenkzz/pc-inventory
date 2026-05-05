<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function fixturePath(string $path): string
    {
        return base_path('tests/Fixtures/' . ltrim($path, '/\\'));
    }

    protected function fixtureContents(string $path): string
    {
        return (string) file_get_contents($this->fixturePath($path));
    }
}
