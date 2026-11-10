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

use App\Models\Invoice;
use App\Models\Credit;
use App\Services\EDocument\Standards\Montenegro\Enums\ExemptFromVat;
use App\Services\EDocument\Standards\Montenegro\Enums\FeeType;
use App\Services\EDocument\Standards\Montenegro\Support\Money;
use App\Services\EDocument\Standards\Montenegro\Support\XmlSanitizer;

/**
 * InvoiceTotalsCalculator
 *
 * Maps Invoice Ninja line items to the EFI item and SameTaxes structures.
 *
 * The persisted line_total, tax_amount and gross_line_total values produced by
 * Invoice Ninja are used when present so the fiscalized totals reconcile with the
 * invoice amount. Rebates are not emitted (R is omitted) and the unit prices are
 * derived from the line totals so that UPA always equals PA / Q.
 */
class InvoiceTotalsCalculator
{
    public function __construct(private Invoice|Credit $invoice) {}

    public function calculate(): InvoiceTotals
    {
        $invoiceRate = (float) ($this->invoice->tax_rate1 ?? 0)
            + (float) ($this->invoice->tax_rate2 ?? 0)
            + (float) ($this->invoice->tax_rate3 ?? 0);

        $items = [];
        $sameTaxes = [];
        $fees = [];
        $taxFreeAmt = 0.0;
        $totalWithoutVat = 0.0;
        $totalVat = 0.0;
        $total = 0.0;

        foreach (($this->invoice->line_items ?? []) as $rawItem) {
            $quantity = (float) ($rawItem->quantity ?? 0);
            $unitCost = (float) ($rawItem->cost ?? 0);
            $discount = (float) ($rawItem->discount ?? 0);

            $gross = $unitCost * $quantity;
            $discountAmount = !empty($rawItem->is_amount_discount) ? $discount : $gross * $discount / 100;

            $priceBeforeVat = $this->numeric($rawItem->line_total ?? null, $gross - $discountAmount);

            $ownRate = (float) ($rawItem->tax_rate1 ?? 0)
                + (float) ($rawItem->tax_rate2 ?? 0)
                + (float) ($rawItem->tax_rate3 ?? 0);

            $vatRate = $ownRate > 0.0 ? $ownRate : $invoiceRate;

            $vatAmount = $this->numeric($rawItem->tax_amount ?? null, $priceBeforeVat * $vatRate / 100);

            $priceWithVat = $priceBeforeVat + $vatAmount;

            $name = XmlSanitizer::ascii(
                (string) (($rawItem->notes ?? '') !== '' ? $rawItem->notes : (($rawItem->product_key ?? '') !== '' ? $rawItem->product_key : 'Item')),
                50
            );

            $code = XmlSanitizer::ascii((string) ($rawItem->product_key ?? ''), 50);
            $unit = XmlSanitizer::ascii((string) (($rawItem->unit_code ?? '') !== '' ? $rawItem->unit_code : 'C62'), 50);

            // An exempt supply has no VAT. The EFI exemption code is carried on
            // the line item custom field, for example VAT_CL27.
            $exemptFromVat = null;

            if ($vatRate <= 0.0 && $vatAmount <= 0.0) {
                $exemptFromVat = ExemptFromVat::fromSetting((string) ($rawItem->custom_value4 ?? ''))?->value;

                // A zero VAT line carrying a fee type is reported as an EFI fee
                // and excluded from the item and SameTaxes lists.
                $feeType = FeeType::fromSetting((string) ($rawItem->custom_value3 ?? ''));

                if ($feeType !== null) {
                    $fees[] = new FeeTotals($feeType, Money::round($priceWithVat));
                    $totalWithoutVat += Money::round($priceBeforeVat);
                    $total += Money::round($priceWithVat);

                    continue;
                }
            }

            $item = new ItemTotals(
                name: $name !== '' ? $name : 'Item',
                code: $code !== '' ? $code : null,
                unit: $unit !== '' ? $unit : 'C62',
                quantity: $quantity,
                unitPriceBeforeVat: $quantity != 0.0 ? abs($priceBeforeVat / $quantity) : 0.0,
                unitPriceWithVat: $quantity != 0.0 ? abs($priceWithVat / $quantity) : 0.0,
                priceBeforeVat: Money::round($priceBeforeVat),
                vatRate: $vatRate > 0.0 ? round($vatRate, 2) : 0.0,
                vatAmount: Money::round($vatAmount),
                priceWithVat: Money::round($priceWithVat),
                exemptFromVat: $exemptFromVat,
            );

            $items[] = $item;

            if ($item->isTaxFree()) {
                $taxFreeAmt += $item->priceBeforeVat;
            } else {
                $key = number_format($item->vatRate, 2, '.', '');

                if (!isset($sameTaxes[$key])) {
                    $sameTaxes[$key] = new SameTaxTotals(vatRate: $item->vatRate);
                }

                $sameTaxes[$key]->add($item);
            }

            $totalWithoutVat += $item->priceBeforeVat;
            $totalVat += $item->vatAmount;
            $total += $item->priceWithVat;
        }

        return new InvoiceTotals(
            items: $items,
            sameTaxes: array_values($sameTaxes),
            fees: $fees,
            taxFreeAmt: Money::round($taxFreeAmt),
            totalWithoutVat: Money::round($totalWithoutVat),
            totalVat: Money::round($totalVat),
            total: Money::round($total),
        );
    }

    private function numeric(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }
}
