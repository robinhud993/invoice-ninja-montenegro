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
 * EFI TCRSType, the type of electronic fiscal device being registered.
 */
enum TcrType: string
{
    case REGULAR = 'REGULAR';
    case VENDING = 'VENDING';

    public function label(): string
    {
        return match ($this) {
            self::REGULAR => 'Regular TCR',
            self::VENDING => 'Self-vending machine',
        };
    }
}
