<?php

namespace Kstmostofa\LaravelWhatsApp\Support;

use InvalidArgumentException;

/**
 * Cloud API requires recipient phone numbers as digits only — no `+`, no spaces,
 * no parentheses, no leading zeros. This helper normalizes user input.
 */
class Recipient
{
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '' || strlen($digits) < 6) {
            throw new InvalidArgumentException("Invalid WhatsApp recipient: {$phone}");
        }

        return ltrim($digits, '0') === '' ? $digits : ltrim($digits, '0');
    }
}
