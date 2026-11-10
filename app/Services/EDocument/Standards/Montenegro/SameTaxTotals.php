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
 * SameTaxTotals
 *
 * A group of items sharing the same VAT rate (me:SameTaxType).
 */
class SameTaxTotals
{
    public function __construct(
        public readonly float $vatRate,
        public int $numOfItems = 0,
        public float $priceBeforeVat = 0.0,
        public float $vatAmount = 0.0,
        public ?string $exemptFromVat = null,
    ) {}

    public function add(ItemTotals $item): void
    {
        $this->numOfItems++;
        $this->priceBeforeVat += $item->priceBeforeVat;
        $this->vatAmount += $item->vatAmount;

        if ($this->exemptFromVat === null && $item->exemptFromVat !== null) {
            $this->exemptFromVat = $item->exemptFromVat;
        }
    }

    public function key(): string
    {
        return number_format($this->vatRate, 2, '.', '');
    }
}
