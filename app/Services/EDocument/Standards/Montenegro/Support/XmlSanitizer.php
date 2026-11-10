<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Services\EDocument\Standards\Montenegro\Support;

/**
 * XmlSanitizer
 *
 * EFI data messages only allow the decimal ASCII range, codes 9, 10, 13 and
 * 32 to 126 (technical specification section 3.2). All user supplied strings must
 * therefore be transliterated to ASCII and trimmed to the schema length.
 */
class XmlSanitizer
{
    public static function ascii(?string $value, int $maxLength = 0): string
    {
        if ($value === null) {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], ' ', $value);

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($converted === false) {
            $converted = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value) ?? '';
        }

        $converted = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $converted) ?? '';
        $converted = preg_replace('/\s+/', ' ', $converted) ?? '';
        $converted = trim($converted);

        if ($maxLength > 0 && strlen($converted) > $maxLength) {
            $converted = rtrim(substr($converted, 0, $maxLength));
        }

        return $converted;
    }

    /**
     * The RegistrationCodeSType is strictly lowercase two letters, three digits,
     * two letters, three digits. Values are only trimmed, never case folded, so a
     * misconfigured code fails schema validation instead of being silently altered.
     */
    public static function registrationCode(?string $value): string
    {
        return trim((string) $value);
    }

    public static function isRegistrationCode(string $value): bool
    {
        return (bool) preg_match('/^[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}$/', $value);
    }

    public static function isTin(string $value): bool
    {
        return (bool) preg_match('/^[0-9]{13}$|^[0-9]{8}$/', trim($value));
    }
}
