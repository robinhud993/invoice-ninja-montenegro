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
 * EFI PaymentMethodTypeSType.
 *
 * Technical specification v5 Table 90. CHECK, COMPENSATION, TRANSFER, WAIVER and
 * KIND were removed on 1.10.2021; BUSINESSCARD and OTHER-CASH were added. ORDER
 * is allowed on both cash and non-cash invoices.
 */
enum PaymentMethod: string
{
    case BANKNOTE = 'BANKNOTE';
    case CARD = 'CARD';
    case BUSINESSCARD = 'BUSINESSCARD';
    case SVOUCHER = 'SVOUCHER';
    case COMPANY = 'COMPANY';
    case ORDER = 'ORDER';
    case ADVANCE = 'ADVANCE';
    case ACCOUNT = 'ACCOUNT';
    case FACTORING = 'FACTORING';
    case OTHER = 'OTHER';
    case OTHER_CASH = 'OTHER-CASH';

    /**
     * The invoice type an invoice must declare to be allowed to use this method.
     */
    public function invoiceType(): InvoiceType
    {
        return match ($this) {
            self::BANKNOTE,
            self::CARD,
            self::ORDER,
            self::OTHER_CASH => InvoiceType::CASH,
            default => InvoiceType::NONCASH,
        };
    }

    public function isAdvance(): bool
    {
        return $this === self::ADVANCE;
    }

    public static function fromSetting(?string $value, self $default = self::ACCOUNT): self
    {
        if (is_null($value)) {
            return $default;
        }

        return self::tryFrom(strtoupper(trim($value))) ?? $default;
    }
}
