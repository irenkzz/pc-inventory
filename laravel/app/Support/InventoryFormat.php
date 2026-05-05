<?php

namespace App\Support;

class InventoryFormat
{
    public static function locale(): string
    {
        $locale = trim((string) config('inventory.display_locale', ''));
        if ($locale === '') {
            $locale = self::requestLocale();
        }

        if ($locale === '') {
            $locale = trim((string) config('app.locale', 'en_US'));
        }

        return self::normalizeLocale($locale !== '' ? $locale : 'en_US') ?? 'en_US';
    }

    public static function number(mixed $value, string $fallback = '-'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (! is_numeric($value)) {
            return trim((string) $value) !== '' ? (string) $value : $fallback;
        }

        return number_format((float) $value, 0, self::decimalSeparator(), self::thousandsSeparator());
    }

    public static function decimal(mixed $value, int $precision = 1, string $fallback = '-'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (! is_numeric($value)) {
            return trim((string) $value) !== '' ? (string) $value : $fallback;
        }

        return number_format((float) $value, $precision, self::decimalSeparator(), self::thousandsSeparator());
    }

    public static function percent(mixed $value, string $fallback = '-'): string
    {
        return self::number($value, $fallback) . (is_numeric($value) ? '%' : '');
    }

    public static function dateTimeFormat(): string
    {
        $configured = config('inventory.display_datetime_format');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return self::usesIndonesianRegion()
            ? 'd M Y H:i'
            : 'M j, Y g:i A';
    }

    public static function decimalSeparator(): string
    {
        $configured = config('inventory.display_decimal_separator');
        if ($configured !== null && (string) $configured !== '') {
            return (string) $configured;
        }

        return self::usesIndonesianRegion() ? ',' : '.';
    }

    public static function thousandsSeparator(): string
    {
        $configured = config('inventory.display_thousands_separator');
        if ($configured !== null && (string) $configured !== '') {
            return (string) $configured;
        }

        return self::usesIndonesianRegion() ? '.' : ',';
    }

    private static function usesIndonesianRegion(): bool
    {
        $locale = strtolower(self::locale());

        return str_starts_with($locale, 'id') || str_ends_with($locale, '_id');
    }

    private static function requestLocale(): string
    {
        if (! app()->bound('request')) {
            return '';
        }

        $header = (string) request()->headers->get('Accept-Language', '');
        foreach (explode(',', $header) as $item) {
            $locale = trim((string) preg_replace('/;q=.*$/', '', $item));
            $normalized = self::normalizeLocale($locale);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return '';
    }

    private static function normalizeLocale(string $locale): ?string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return null;
        }

        $locale = preg_replace('/\..*$/', '', $locale) ?? $locale;
        $locale = str_replace('-', '_', $locale);

        if (! preg_match('/^[a-zA-Z]{2,3}(_[a-zA-Z]{2})?$/', $locale)) {
            return null;
        }

        [$language, $region] = array_pad(explode('_', $locale, 2), 2, '');

        return $region === '' ? strtolower($language) : strtolower($language) . '_' . strtoupper($region);
    }
}
