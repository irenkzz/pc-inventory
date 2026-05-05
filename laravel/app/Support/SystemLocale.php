<?php

namespace App\Support;

class SystemLocale
{
    public static function detect(): string
    {
        foreach (['APP_LOCALE', 'LC_ALL', 'LC_TIME', 'LANG'] as $key) {
            $locale = self::normalize((string) getenv($key));
            if ($locale !== null) {
                return $locale;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $locale = self::windowsLocale();
            if ($locale !== null) {
                return $locale;
            }
        }

        return 'en_US';
    }

    private static function windowsLocale(): ?string
    {
        if (! function_exists('exec') || self::functionDisabled('exec')) {
            return null;
        }

        $output = [];
        $exitCode = 1;
        @exec('powershell -NoProfile -Command "(Get-Culture).Name"', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        return self::normalize((string) $output[0]);
    }

    private static function normalize(string $locale): ?string
    {
        $locale = trim($locale);
        if ($locale === '' || strtoupper($locale) === 'C' || strtoupper($locale) === 'POSIX') {
            return null;
        }

        $locale = preg_replace('/\..*$/', '', $locale) ?? $locale;
        $locale = str_replace('-', '_', $locale);

        if (! preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale)) {
            return null;
        }

        [$language, $region] = array_pad(explode('_', $locale, 2), 2, '');

        return $region === '' ? strtolower($language) : strtolower($language) . '_' . strtoupper($region);
    }

    private static function functionDisabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $disabled, true);
    }
}
