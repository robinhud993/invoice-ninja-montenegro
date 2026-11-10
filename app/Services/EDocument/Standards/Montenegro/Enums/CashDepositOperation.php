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
 * EFI CashDepositOperationSType.
 */
enum CashDepositOperation: string
{
    case INITIAL = 'INITIAL';
    case WITHDRAW = 'WITHDRAW';

    public function label(): string
    {
        return match ($this) {
            self::INITIAL => 'Initial deposit in the TCR',
            self::WITHDRAW => 'Cash withdrawn from the TCR',
        };
    }
}
