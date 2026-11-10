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
 * InvoiceTotals
 *
 * Aggregated EFI totals for an invoice, including the SameTaxes grouping used by
 * the fiscalization service to cross check VAT amounts.
 */
class InvoiceTotals
{
    /**
     * @param array<int, ItemTotals> $items
     * @param array<int, SameTaxTotals> $sameTaxes
     * @param array<int, FeeTotals> $fees
     */
    public function __construct(
        public array $items = [],
        public array $sameTaxes = [],
        public array $fees = [],
        public float $taxFreeAmt = 0.0,
        public float $totalWithoutVat = 0.0,
        public float $totalVat = 0.0,
        public float $total = 0.0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
