<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

class InventoryTime
{
    public static function parseForStorage(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if ($value instanceof CarbonInterface) {
                $time = $value->copy();
            } elseif ($value instanceof DateTimeInterface) {
                $time = CarbonImmutable::instance($value);
            } else {
                $time = CarbonImmutable::parse((string) $value, config('app.timezone'));
            }
        } catch (\Throwable) {
            return null;
        }

        return $time->timezone(config('app.timezone'));
    }

    public static function storageString(mixed $value, string $fallback = ''): string
    {
        $time = self::parseForStorage($value);
        if ($time === null) {
            return $fallback;
        }

        return $time->format('Y-m-d H:i:s');
    }

    public static function format(mixed $value, string $fallback = '-'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            if ($value instanceof CarbonInterface) {
                $time = $value->copy();
            } elseif ($value instanceof DateTimeInterface) {
                $time = CarbonImmutable::instance($value);
            } else {
                $time = CarbonImmutable::parse((string) $value, config('app.timezone'));
            }
        } catch (\Throwable) {
            return trim((string) $value) !== '' ? (string) $value : $fallback;
        }

        $formatted = $time
            ->timezone(self::timezone())
            ->format(InventoryFormat::dateTimeFormat());

        $label = self::label();

        return $label === '' ? $formatted : "{$formatted} {$label}";
    }

    public static function timezone(): string
    {
        $timezone = config('inventory.display_timezone');

        return trim((string) $timezone) !== '' ? (string) $timezone : (string) config('app.timezone', 'UTC');
    }

    public static function label(): string
    {
        $configured = config('inventory.display_timezone_label');
        if ($configured !== null && trim((string) $configured) !== '') {
            return trim((string) $configured);
        }

        try {
            return CarbonImmutable::now(self::timezone())->format('T');
        } catch (\Throwable) {
            return '';
        }
    }
}
