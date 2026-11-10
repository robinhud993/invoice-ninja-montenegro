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
 * EFI ExemptFromVATSType, the item level EX attribute.
 *
 * The value is read from the line item custom field selected by
 * FiscalSettings::exemptCustomField(), default custom_value4.
 */
enum ExemptFromVat: string
{
    case CLASS_17 = 'VAT_CL17';
    case CLASS_20 = 'VAT_CL20';
    case PUBLIC_INTEREST = 'VAT_CL26';
    case OTHER = 'VAT_CL27';
    case IMPORTS = 'VAT_CL28';
    case TEMPORARY_IMPORTS = 'VAT_CL29';
    case SPECIAL = 'VAT_CL30';
    case CLASS_44 = 'VAT_CL44';

    public function label(): string
    {
        return match ($this) {
            self::CLASS_17 => 'VAT exemption class 17',
            self::CLASS_20 => 'VAT exemption class 20',
            self::PUBLIC_INTEREST => 'Exemptions from the public interest',
            self::OTHER => 'Other exemptions',
            self::IMPORTS => 'Exemptions on imports of products',
            self::TEMPORARY_IMPORTS => 'Exemptions for temporary importation of products',
            self::SPECIAL => 'Special exemptions',
            self::CLASS_44 => 'Special taxation procedure',
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
