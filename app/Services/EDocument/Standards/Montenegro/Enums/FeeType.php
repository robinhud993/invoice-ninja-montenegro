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

namespace App\Services\EDocument\Standards\Montenegro\Enums;

/**
 * EFI FeeTypeSType, the invoice level fees reported in the Fees element.
 *
 * The fee type is read from the line item custom field selected by
 * FiscalSettings::feeCustomField(), default custom_value3. A line item carrying
 * a fee type is reported as a fee rather than as a regular item, and must not
 * carry VAT.
 */
enum FeeType: string
{
    // Technical specification v5 section 3.7.1.121: BOTTLE was removed on
    // 1.10.2021, so only these three values are valid.
    case PACK = 'PACK';
    case COMMISSION = 'COMMISSION';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::PACK => 'Packaging fee',
            self::COMMISSION => 'Commission for currency exchange activities',
            self::OTHER => 'Other fees',
        };
    }

    public static function fromSetting(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(strtoupper(trim($value)));
    }
}
