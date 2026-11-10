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
 * EFI InvoiceType (me:InvoiceSType).
 *
 * CASH covers notes, coins, cards, cheques, vouchers and advance payments.
 * NONCASH covers transaction accounts, factoring, compensation and similar.
 */
enum InvoiceType: string
{
    case CASH = 'CASH';
    case NONCASH = 'NONCASH';

    /**
     * CASH invoices must carry the ENU (TCR) code and a number that includes it.
     */
    public function requiresTcrCode(): bool
    {
        return $this === self::CASH;
    }

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash payment',
            self::NONCASH => 'Non-cash payment',
        };
    }
}
