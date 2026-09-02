<?php

namespace App\Support;

class CountryFlag
{
    /**
     * Convert an ISO 3166-1 alpha-2 country code to its flag emoji.
     * Falls back to an empty string when the code is not two letters.
     */
    public static function emoji(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }

        $offset = 0x1F1E6 - ord('A');

        return mb_convert_encoding('&#'.($offset + ord($code[0])).';', 'UTF-8', 'HTML-ENTITIES')
            .mb_convert_encoding('&#'.($offset + ord($code[1])).';', 'UTF-8', 'HTML-ENTITIES');
    }
}
