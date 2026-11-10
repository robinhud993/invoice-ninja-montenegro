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

namespace App\Services\EDocument\Standards\Montenegro\Xml;

use App\Models\Invoice;
use App\Models\Credit;
use DOMDocument;
use DOMElement;
use App\Services\EDocument\Standards\Montenegro\FiscalSettings;
use App\Services\EDocument\Standards\Montenegro\InvoiceTotals;
use App\Services\EDocument\Standards\Montenegro\CorrectiveReference;
use App\Services\EDocument\Standards\Montenegro\Enums\InvoiceType;
use App\Services\EDocument\Standards\Montenegro\Enums\PaymentMethod;
use App\Services\EDocument\Standards\Montenegro\Enums\DocumentKind;
use App\Services\EDocument\Standards\Montenegro\Enums\SubseqDelivType;
use App\Services\EDocument\Standards\Montenegro\Support\Money;
use App\Services\EDocument\Standards\Montenegro\Support\XmlSanitizer;

/**
 * DocumentBuilder
 *
 * Builds the unsigned me:RegisterInvoiceRequest document defined in the
 * Fiscalization service technical specification, section 3.7.1 and the
 * eficg-fiscalization-service.xsd schema.
 *
 * The signature element is appended later by the Signer, so the returned document
 * is not yet schema complete.
 */
class DocumentBuilder
{
    public const NAMESPACE = 'https://efi.tax.gov.me/fs/schema';

    public function __construct(private Invoice|Credit $invoice, private FiscalSettings $settings) {}

    public function build(
        InvoiceTotals $totals,
        string $iic,
        string $iicSignature,
        string $invoiceNumber,
        int $ordinal,
        string $issueDateTime,
        InvoiceType $invoiceType,
        PaymentMethod $paymentMethod,
        string $requestUuid,
        string $sendDateTime,
        ?SubseqDelivType $subseq = null,
        ?CorrectiveReference $corrective = null,
        bool $isBadDebt = false,
        DocumentKind $kind = DocumentKind::CREATE,
    ): DOMDocument {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;

        $root = $doc->createElementNS(self::NAMESPACE, 'RegisterInvoiceRequest');
        $root->setAttribute('Id', 'Request');
        $root->setAttribute('Version', '1');
        $doc->appendChild($root);

        $root->appendChild($this->header($doc, $requestUuid, $sendDateTime, $subseq));

        $invoice = $this->element($doc, 'Invoice');

        // Technical specification v5 section 3.7.1.6: InvType is mandatory from
        // 1.10.2021. IsSimplifiedInv was removed on the same date.
        $invoice->setAttribute('InvType', $kind->invType());
        $invoice->setAttribute('TypeOfInv', $invoiceType->value);
        $invoice->setAttribute('IssueDateTime', $issueDateTime);
        $invoice->setAttribute('InvNum', $invoiceNumber);
        $invoice->setAttribute('InvOrdNum', (string) $ordinal);

        // v4.1: the ENU (TCR) code is mandatory on every invoice, cash or not.
        $invoice->setAttribute('TCRCode', XmlSanitizer::registrationCode($this->settings->tcrCode()));

        $invoice->setAttribute('IsIssuerInVAT', $this->settings->isInVat() ? 'true' : 'false');
        $invoice->setAttribute('TotPriceWoVAT', Money::decimal($totals->totalWithoutVat));

        // The CIS rejects TotVATAmt when the taxpayer is not obligated to
        // declare VAT (error 11: "TotVATAmt attribute must not exist if taxpayer
        // is not obligated to declare VAT"), so it is only emitted when the
        // issuer declares VAT.
        if ($this->settings->isInVat()) {
            $invoice->setAttribute('TotVATAmt', Money::decimal($totals->totalVat));
        }

        $invoice->setAttribute('TotPrice', Money::decimal($totals->total));
        $invoice->setAttribute('OperatorCode', XmlSanitizer::registrationCode($this->settings->operatorCode()));
        $invoice->setAttribute('BusinUnitCode', XmlSanitizer::registrationCode($this->settings->businessUnitCode()));
        $invoice->setAttribute('SoftCode', XmlSanitizer::registrationCode($this->settings->softwareCode()));
        $invoice->setAttribute('IIC', $iic);
        $invoice->setAttribute('IICSignature', $iicSignature);
        // v4.2 changelog section 3.7.1.31: this field must always be false.
        $invoice->setAttribute('IsReverseCharge', 'false');

        if ($totals->taxFreeAmt > 0.0) {
            $invoice->setAttribute('TaxFreeAmt', Money::decimal($totals->taxFreeAmt));
        }

        if ($invoiceType === InvoiceType::NONCASH && $this->invoice->due_date) {
            $invoice->setAttribute('PayDeadline', \Carbon\Carbon::parse($this->invoice->due_date)->format('Y-m-d'));
        }

        // The CIS requires every buyer field when self-issuing is declared
        // (error 11), so only declare it when the buyer is fully populated.
        if ($selfIss = $this->settings->selfIssType()) {
            if ($this->buyerIsComplete()) {
                $invoice->setAttribute('TypeOfSelfIss', $selfIss);
            } else {
                nlog('MONTENEGRO FISCALIZATION WARNING: TypeOfSelfIss "' . $selfIss . '" skipped for invoice ' . $this->invoice->number . ' because the buyer is incomplete.');
            }
        }

        if ($bankAccount = $this->settings->bankAccount()) {
            $invoice->setAttribute('BankAccNum', $bankAccount);
        }

        if ($note = $this->note()) {
            $invoice->setAttribute('Note', $note);
        }

        // TotPriceToPay differs from TotPrice only when part of the invoice is
        // already settled (specification 3.7.1.21).
        $outstanding = Money::round((float) $this->invoice->amount - (float) $this->invoice->paid_to_date);

        if ($outstanding > 0.0 && abs($outstanding - $totals->total) > 0.001) {
            $invoice->setAttribute('TotPriceToPay', Money::decimal($outstanding));
        }

        if ($supply = $this->supplyDateOrPeriod($doc)) {
            $invoice->appendChild($supply);
        }

        if ($corrective !== null && !$isBadDebt) {
            $invoice->appendChild($this->correctiveInv($doc, $corrective));
        }

        if ($isBadDebt && $corrective !== null) {
            $invoice->appendChild($this->badDebtInv($doc, $corrective));
        }

        $invoice->appendChild($this->payMethods($doc, $paymentMethod, $totals->total));
        $invoice->appendChild($this->seller($doc));

        $buyer = $this->buyer($doc);

        if ($buyer !== null) {
            $invoice->appendChild($buyer);
        }

        $invoice->appendChild($this->items($doc, $totals));

        if ($totals->sameTaxes !== []) {
            $invoice->appendChild($this->sameTaxes($doc, $totals));
        }

        if ($totals->fees !== []) {
            $invoice->appendChild($this->fees($doc, $totals));
        }

        if ($currency = $this->currency($doc)) {
            $invoice->appendChild($currency);
        }

        $root->appendChild($invoice);

        return $doc;
    }

    private function header(DOMDocument $doc, string $uuid, string $sendDateTime, ?SubseqDelivType $subseq): DOMElement
    {
        $header = $this->element($doc, 'Header');
        $header->setAttribute('UUID', $uuid);
        $header->setAttribute('SendDateTime', $sendDateTime);

        if ($subseq !== null) {
            $header->setAttribute('SubseqDelivType', $subseq->value);
        }

        return $header;
    }

    private function correctiveInv(DOMDocument $doc, CorrectiveReference $corrective): DOMElement
    {
        $element = $this->element($doc, 'CorrectiveInv');
        $element->setAttribute('IICRef', $corrective->iic);
        $element->setAttribute('IssueDateTime', $corrective->issueDateTime);
        $element->setAttribute('Type', $corrective->type?->value ?? 'CORRECTIVE');

        return $element;
    }

    private function badDebtInv(DOMDocument $doc, CorrectiveReference $corrective): DOMElement
    {
        $element = $this->element($doc, 'BadDebtInv');
        $element->setAttribute('IICRef', $corrective->iic);
        $element->setAttribute('IssueDateTime', $corrective->issueDateTime);

        return $element;
    }

    private function payMethods(DOMDocument $doc, PaymentMethod $paymentMethod, float $total): DOMElement
    {
        $payMethods = $this->element($doc, 'PayMethods');

        // An advance already paid is reported as its own ADVANCE method, the
        // remainder is settled with the configured method. The methods must sum
        // to the invoice total.
        $advance = (float) ($this->invoice->backup->advance_amount ?? 0);

        // The PayMethod list may not contain duplicate types, so when the
        // configured method is ADVANCE the whole total is reported once.
        if ($paymentMethod === PaymentMethod::ADVANCE) {
            $advance = 0.0;
        }

        $advance = $advance > 0 ? min($advance, abs($total)) : 0.0;
        $remaining = Money::round($total - $advance);

        if ($advance > 0) {
            $advanceMethod = $this->element($doc, 'PayMethod');
            $advanceMethod->setAttribute('Type', PaymentMethod::ADVANCE->value);
            $advanceMethod->setAttribute('Amt', Money::decimal($advance));

            // Specification 3.7.1.54: a pay method of type ADVANCE must carry
            // the IIC of the advance invoice it settles.
            $advanceIic = (string) ($this->invoice->backup->advance_iic ?? '');

            if ($advanceIic !== '') {
                $advanceMethod->setAttribute('AdvIIC', $advanceIic);
            }

            $payMethods->appendChild($advanceMethod);
        }

        if ($remaining !== 0.0 || $advance === 0.0) {
            $payMethod = $this->element($doc, 'PayMethod');
            $payMethod->setAttribute('Type', $paymentMethod->value);
            $payMethod->setAttribute('Amt', Money::decimal($remaining));

            if ($paymentMethod === PaymentMethod::ACCOUNT) {
                if ($bankAccount = $this->settings->bankAccount()) {
                    $payMethod->setAttribute('BankAcc', $bankAccount);
                }
            } elseif ($paymentMethod === PaymentMethod::COMPANY) {
                if ($compCard = $this->settings->compCard()) {
                    $payMethod->setAttribute('CompCard', $compCard);
                }
            }

            $payMethods->appendChild($payMethod);
        }

        return $payMethods;
    }

    /**
     * Invoice note reported as me:Note, truncated to the schema limit of 200
     * characters.
     */
    private function note(): ?string
    {
        $note = trim((string) ($this->invoice->public_notes ?? ''));

        if ($note === '') {
            return null;
        }

        return mb_substr($note, 0, 200);
    }

    private function seller(DOMDocument $doc): DOMElement
    {
        $seller = $this->element($doc, 'Seller');
        $seller->setAttribute('IDType', 'TIN');
        $seller->setAttribute('IDNum', trim($this->settings->tin()));
        $seller->setAttribute('Name', $this->companyName());
        $seller->setAttribute('Country', $this->companyCountryCode());

        $address = XmlSanitizer::ascii((string) $this->settings->company->getSetting('address1'), 200);
        $town = XmlSanitizer::ascii((string) $this->settings->company->getSetting('city'), 100);

        if ($address !== '') {
            $seller->setAttribute('Address', $address);
        }

        if ($town !== '') {
            $seller->setAttribute('Town', $town);
        }

        return $seller;
    }

    private function buyer(DOMDocument $doc): ?DOMElement
    {
        $client = $this->invoice->client;

        if ($client === null) {
            return null;
        }

        $buyer = $this->element($doc, 'Buyer');
        $buyer->setAttribute('Name', XmlSanitizer::ascii((string) $client->name, 100) ?: 'Buyer');

        $idNumber = XmlSanitizer::ascii((string) ($client->vat_number ?: $client->id_number), 20);

        if ($idNumber !== '') {
            $buyer->setAttribute('IDType', 'TIN');
            $buyer->setAttribute('IDNum', $idNumber);
        }

        $address = XmlSanitizer::ascii((string) $client->address1, 200);
        $town = XmlSanitizer::ascii((string) $client->city, 100);

        if ($address !== '') {
            $buyer->setAttribute('Address', $address);
        }

        if ($town !== '') {
            $buyer->setAttribute('Town', $town);
        }

        $buyer->setAttribute('Country', $this->clientCountryCode());

        return $buyer;
    }

    /**
     * The CIS requires Name, IDType/IDNum, Address, Town and Country when
     * self-issuing or export of goods is declared (error 11).
     */
    private function buyerIsComplete(): bool
    {
        $client = $this->invoice->client;

        if ($client === null) {
            return false;
        }

        $id = trim((string) ($client->vat_number ?: $client->id_number));
        $address = trim((string) $client->address1);
        $town = trim((string) $client->city);

        return trim((string) $client->name) !== ''
            && $id !== ''
            && $address !== ''
            && $town !== ''
            && trim((string) $this->clientCountryCode()) !== '';
    }

    private function items(DOMDocument $doc, InvoiceTotals $totals): DOMElement
    {
        $items = $this->element($doc, 'Items');

        foreach ($totals->items as $item) {
            $element = $this->element($doc, 'I');
            $element->setAttribute('N', $item->name);
            $element->setAttribute('U', $item->unit);
            $element->setAttribute('Q', Money::quantity($item->quantity));
            $element->setAttribute('UPB', Money::decimal($item->unitPriceBeforeVat));
            $element->setAttribute('UPA', Money::decimal($item->unitPriceWithVat));
            $element->setAttribute('PB', Money::decimal($item->priceBeforeVat));
            $element->setAttribute('PA', Money::decimal($item->priceWithVat));

            if ($item->code !== null) {
                $element->setAttribute('C', $item->code);
            }

            if (!$item->isTaxFree()) {
                $element->setAttribute('VR', Money::decimal($item->vatRate));
                $element->setAttribute('VA', Money::decimal($item->vatAmount));
            }

            if ($item->exemptFromVat !== null) {
                $element->setAttribute('EX', $item->exemptFromVat);
            }

            $items->appendChild($element);
        }

        return $items;
    }

    private function sameTaxes(DOMDocument $doc, InvoiceTotals $totals): DOMElement
    {
        $sameTaxes = $this->element($doc, 'SameTaxes');

        foreach ($totals->sameTaxes as $sameTax) {
            $element = $this->element($doc, 'SameTax');
            $element->setAttribute('NumOfItems', (string) $sameTax->numOfItems);
            $element->setAttribute('PriceBefVAT', Money::decimal($sameTax->priceBeforeVat));
            $element->setAttribute('VATRate', Money::decimal($sameTax->vatRate));
            $element->setAttribute('VATAmt', Money::decimal($sameTax->vatAmount));

            // The SameTax ExemptFromVAT attribute uses the same VAT_CLxx codes
            // as the item level EX attribute (specification 3.7.1.109).
            if ($sameTax->exemptFromVat !== null && $sameTax->exemptFromVat !== '') {
                $element->setAttribute('ExemptFromVAT', $sameTax->exemptFromVat);
            }

            $sameTaxes->appendChild($element);
        }

        return $sameTaxes;
    }

    /**
     * The supply date or period is read from the invoice custom fields 3
     * (start) and 4 (end) when both hold valid dates, mirroring the convention
     * used by the FacturaE standard in this codebase.
     */
    private function supplyDateOrPeriod(DOMDocument $doc): ?DOMElement
    {
        $start = $this->toDate($this->invoice->custom_value3 ?? null);
        $end = $this->toDate($this->invoice->custom_value4 ?? null);

        if ($start === null || $end === null) {
            return null;
        }

        $element = $this->element($doc, 'SupplyDateOrPeriod');
        $element->setAttribute('Start', $start);
        $element->setAttribute('End', $end);

        return $element;
    }

    private function fees(DOMDocument $doc, InvoiceTotals $totals): DOMElement
    {
        $fees = $this->element($doc, 'Fees');

        foreach ($totals->fees as $fee) {
            $element = $this->element($doc, 'Fee');
            $element->setAttribute('Type', $fee->type->value);
            $element->setAttribute('Amt', Money::decimal($fee->amount));
            $fees->appendChild($element);
        }

        return $fees;
    }

    private function toDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse(trim($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function currency(DOMDocument $doc): ?DOMElement
    {
        $code = $this->currencyCode();

        if ($code === 'EUR') {
            return null;
        }

        $rate = (float) ($this->invoice->exchange_rate ?? 0);

        if ($rate <= 0.0) {
            $rate = (float) ($this->invoice->client?->currency()?->exchange_rate ?? 0);
        }

        if ($rate <= 0.0) {
            return null;
        }

        $currency = $this->element($doc, 'Currency');
        $currency->setAttribute('Code', $code);
        $currency->setAttribute('ExRate', (string) $rate);

        return $currency;
    }

    private function companyName(): string
    {
        return XmlSanitizer::ascii((string) $this->settings->company->getSetting('name'), 100) ?: 'Seller';
    }

    private function companyCountryCode(): string
    {
        $country = $this->settings->company->country();

        return XmlSanitizer::ascii((string) $country?->iso_3166_3, 3) ?: 'MNE';
    }

    private function clientCountryCode(): string
    {
        $country = $this->invoice->client?->country;

        return XmlSanitizer::ascii((string) $country?->iso_3166_3, 3) ?: 'MNE';
    }

    private function currencyCode(): string
    {
        $code = (string) ($this->invoice->client?->currency()?->code ?? $this->settings->company->currency()?->code ?? 'EUR');

        return XmlSanitizer::ascii($code, 3) ?: 'EUR';
    }

    private function element(DOMDocument $doc, string $name): DOMElement
    {
        return $doc->createElementNS(self::NAMESPACE, $name);
    }
}
