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
 * Money
 *
 * EFI decimal fields are fixed two decimal strings using a dot separator. The
 * DecimalSType pattern is (([1-9][0-9]*|0)\.[0-9]{2}|0), so a value of zero must
 * be serialised as "0" (not "0.00"). Negative values are allowed on
 * DecimalNegSType fields.
 */
class Money
{
    public static function decimal(float|int|string|null $value): string
    {
        $value = self::round($value);

        if ($value === 0.0) {
            return '0';
        }

        return number_format($value, 2, '.', '');
    }

    public static function quantity(float|int|string|null $value): string
    {
        $value = round((float) $value, 3);

        if ($value === 0.0) {
            return '0';
        }

        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    public static function round(float|int|string|null $value): float
    {
        $rounded = round((float) $value, 2);

        return $rounded === 0.0 ? 0.0 : $rounded;
    }

    public static function toFloat(float|int|string|null $value): float
    {
        return (float) $value;
    }
}
