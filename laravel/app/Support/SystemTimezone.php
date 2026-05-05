<?php

namespace App\Support;

use DateTimeZone;

class SystemTimezone
{
    /**
     * Detect the server timezone without hardcoding a branch/location timezone.
     */
    public static function detect(): string
    {
        $envTimezone = self::validTimezone((string) getenv('TZ'));
        if ($envTimezone !== null) {
            return $envTimezone;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $windowsTimezone = self::windowsTimezone();
            if ($windowsTimezone !== null) {
                return $windowsTimezone;
            }
        }

        return self::validTimezone(date_default_timezone_get()) ?? 'UTC';
    }

    private static function windowsTimezone(): ?string
    {
        $windowsId = self::windowsTimezoneId();
        if ($windowsId === null) {
            return null;
        }

        return self::windowsTimezoneMap()[$windowsId] ?? null;
    }

    private static function windowsTimezoneId(): ?string
    {
        if (! function_exists('exec') || self::functionDisabled('exec')) {
            return null;
        }

        $output = [];
        $exitCode = 1;
        @exec('tzutil /g', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $timezone = trim((string) $output[0]);

        return $timezone !== '' ? $timezone : null;
    }

    private static function validTimezone(string $timezone): ?string
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            return null;
        }

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : null;
    }

    private static function functionDisabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $disabled, true);
    }

    /**
     * Windows IDs use Microsoft names. PHP/Laravel need IANA timezone IDs.
     * Keep common APAC/internal deployment zones here and fall back cleanly.
     */
    private static function windowsTimezoneMap(): array
    {
        return [
            'UTC' => 'UTC',
            'Tokyo Standard Time' => 'Asia/Tokyo',
            'Korea Standard Time' => 'Asia/Seoul',
            'China Standard Time' => 'Asia/Shanghai',
            'Taipei Standard Time' => 'Asia/Taipei',
            'Singapore Standard Time' => 'Asia/Singapore',
            'Malay Peninsula Standard Time' => 'Asia/Kuala_Lumpur',
            'SE Asia Standard Time' => 'Asia/Bangkok',
            'W. Indonesia Standard Time' => 'Asia/Jakarta',
            'Central Asia Standard Time' => 'Asia/Almaty',
            'India Standard Time' => 'Asia/Kolkata',
            'Arabian Standard Time' => 'Asia/Dubai',
            'W. Australia Standard Time' => 'Australia/Perth',
            'AUS Central Standard Time' => 'Australia/Darwin',
            'AUS Eastern Standard Time' => 'Australia/Sydney',
            'E. Australia Standard Time' => 'Australia/Brisbane',
            'New Zealand Standard Time' => 'Pacific/Auckland',
            'Pacific Standard Time' => 'America/Los_Angeles',
            'Mountain Standard Time' => 'America/Denver',
            'Central Standard Time' => 'America/Chicago',
            'Eastern Standard Time' => 'America/New_York',
            'GMT Standard Time' => 'Europe/London',
            'W. Europe Standard Time' => 'Europe/Berlin',
            'Romance Standard Time' => 'Europe/Paris',
        ];
    }
}
