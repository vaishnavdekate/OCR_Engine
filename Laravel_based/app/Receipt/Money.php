<?php

namespace App\Receipt;

/** Monetary arithmetic uses integer cents, never binary floating point. */
final class Money
{
    public static function parse(string $text): ?string
    {
        $value = str_replace(['−', '’'], ['-', "'"], trim($text));
        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = str_replace([' ', "'"], '', trim($value, '()'));
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $decimal = strrpos($value, ',') > strrpos($value, '.') ? ',' : '.';
            $value = str_replace($decimal, '.', str_replace($decimal === ',' ? '.' : ',', '', $value));
        } elseif (str_contains($value, ',')) {
            $fraction = substr($value, strrpos($value, ',') + 1);
            $value = str_replace(',', in_array(strlen($fraction), [1, 2], true) ? '.' : '', $value);
        } elseif (substr_count($value, '.') > 1) {
            $position = strrpos($value, '.');
            $value = str_replace('.', '', substr($value, 0, $position)).substr($value, $position);
        } elseif (str_contains($value, '.') && strlen(substr($value, strrpos($value, '.') + 1)) === 3) {
            $value = str_replace('.', '', $value);
        }
        if (! preg_match('~^-?\d+(?:\.\d{1,2})?$~D', $value)) {
            return null;
        }
        $signed = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'), 2);
        $whole = ltrim($parts[0], '0') ?: '0';
        if (strlen($whole) > 9) {
            return null;
        }
        $cents = (int) $whole * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        return self::format(($negative xor $signed) ? -$cents : $cents);
    }

    public static function cents(string $value): int
    {
        $parts = explode('.', ltrim($value, '-'), 2);
        $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        return str_starts_with($value, '-') ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
