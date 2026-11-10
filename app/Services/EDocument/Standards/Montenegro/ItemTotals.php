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

namespace App\Services\EDocument\Standards\Montenegro;

/**
 * ItemTotals
 *
 * Normalised EFI item values (me:InvoiceItemType) for a single invoice line.
 */
class ItemTotals
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $code,
        public readonly string $unit,
        public readonly float $quantity,
        public readonly float $unitPriceBeforeVat,
        public readonly float $unitPriceWithVat,
        public readonly float $priceBeforeVat,
        public readonly float $vatRate,
        public readonly float $vatAmount,
        public readonly float $priceWithVat,
        public readonly ?string $exemptFromVat = null,
    ) {}

    public function isTaxFree(): bool
    {
        return $this->vatRate <= 0.0 && $this->vatAmount <= 0.0;
    }
}
