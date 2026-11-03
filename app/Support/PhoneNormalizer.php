<?php

namespace App\Support;

class PhoneNormalizer
{
    /**
     * Normalize any phone number string into digits-only E.164 format required by Gupshup.
     * Example: "050 123 4567" (default CC 966) -> "966501234567"
     * Example: "+20 101 234 5678" -> "201012345678"
     */
    public static function toE164(?string $rawPhone, string $defaultCountryCode = '966'): ?string
    {
        if (!$rawPhone) {
            return null;
        }

        // Convert Arabic/Eastern numerals to standard Western numerals
        $arabicNumerals = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $westernNumerals = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $normalized = str_replace($arabicNumerals, $westernNumerals, trim($rawPhone));

        // Strip everything except digits and leading +
        $hasPlus = str_starts_with($normalized, '+');
        $digits = preg_replace('/\D+/', '', $normalized);

        if (empty($digits)) {
            return null;
        }

        $cleanCountryCode = preg_replace('/\D+/', '', $defaultCountryCode) ?: '966';

        // Strip international 00 prefix
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            return $digits ?: null;
        }

        if ($hasPlus) {
            // Handle common mistake like +966050... -> 96650...
            if (str_starts_with($digits, $cleanCountryCode . '0')) {
                $digits = $cleanCountryCode . substr($digits, strlen($cleanCountryCode) + 1);
            }
            return $digits;
        }

        // Egyptian local mobile format: 010xxxxxxxx, 011xxxxxxxx, 012xxxxxxxx, 015xxxxxxxx (11 digits)
        if (strlen($digits) === 11 && preg_match('/^01[0125]\d{8}$/', $digits)) {
            return '20' . substr($digits, 1);
        }

        // Saudi/UAE local mobile format: 05xxxxxxxx (10 digits)
        if (strlen($digits) === 10 && str_starts_with($digits, '05')) {
            return $cleanCountryCode . substr($digits, 1);
        }

        // Saudi 9-digit local mobile without leading 0: 5xxxxxxxx
        if (strlen($digits) === 9 && str_starts_with($digits, '5')) {
            return $cleanCountryCode . $digits;
        }

        // General local number starting with single '0'
        if (str_starts_with($digits, '0')) {
            return $cleanCountryCode . substr($digits, 1);
        }

        // Handle country code followed by extra leading 0 (e.g. 96605...)
        if (str_starts_with($digits, $cleanCountryCode . '0') && strlen($digits) > 11) {
            return $cleanCountryCode . substr($digits, strlen($cleanCountryCode) + 1);
        }

        return $digits;
    }
}

