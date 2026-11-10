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
use App\Models\MontenegroCashDeposit;
use App\Models\MontenegroFiscalCounter;
use App\Models\MontenegroFiscalLog;
use App\Services\AbstractService;
use Illuminate\Support\Str;
use RuntimeException;
use App\Services\EDocument\Standards\Montenegro\Enums\DocumentKind;
use App\Services\EDocument\Standards\Montenegro\Enums\InvoiceType;
use App\Services\EDocument\Standards\Montenegro\Enums\PaymentMethod;
use App\Services\EDocument\Standards\Montenegro\Enums\SubseqDelivType;
use App\Services\EDocument\Standards\Montenegro\Soap\CisClient;
use App\Services\EDocument\Standards\Montenegro\Soap\Envelope;
use App\Services\EDocument\Standards\Montenegro\Soap\ResponseProcessor;
use App\Services\EDocument\Standards\Montenegro\Support\Money;
use App\Services\EDocument\Standards\Montenegro\Xml\DocumentBuilder;
use App\Services\EDocument\Standards\Montenegro\Xml\Signer;

/**
 * Montenegro
 *
 * Orchestrates a single EFI fiscalization request:
 *
 *   1. Resolve the company FiscalSettings.
 *   2. Normalise the invoice into EFI totals and items.
 *   3. Derive the InvNum, InvOrdNum and IssueDateTime.
 *   4. Generate the IKOF (IIC) and its signature (IICSignature).
 *   5. Build and sign the RegisterInvoiceRequest.
 *   6. Wrap it in a SOAP envelope and optionally send it to the CIS.
 *
 * Supports the four requested operations through DocumentKind:
 *   - CREATE  : regular fiscalization
 *   - ADVANCE : advance (avans) invoice, paid by ADVANCE
 *   - CREDIT / CORRECTIVE / DEBIT : storno and corrective invoices referencing the original IIC
 *   - BADDEBT : bad debt declaration referencing the original IIC
 *
 * Subsequent delivery (SubseqDelivType) is passed on the run() call so a failed
 * or offline invoice can be resent within the legally allowed window.
 */
class Montenegro extends AbstractService
{
    private FiscalSettings $settings;

    private ?Certificate $certificate = null;

    private ?InvoiceTotals $totals = null;

    private ?Ikof $ikof = null;

    private ?string $signedEnvelope = null;

    private string $requestUuid;

    private string $issueDateTime;

    private string $sendDateTime;

    private int $ordinal = 0;

    private string $invoiceNumber = '';

    private InvoiceType $invoiceType;

    private PaymentMethod $paymentMethod;

    private DocumentKind $kind = DocumentKind::CREATE;

    private ?CorrectiveReference $corrective = null;

    private ?SubseqDelivType $subseq = null;

    private bool $badDebt = false;

    private bool $built = false;

    public function __construct(public Invoice|Credit $invoice)
    {
        $this->settings = new FiscalSettings($invoice->company, $invoice);
        $this->requestUuid = (string) Str::uuid();
        $this->sendDateTime = $this->now();
        $this->issueDateTime = $this->now();
        $this->invoiceType = $this->settings->defaultInvoiceType();
        $this->paymentMethod = $this->settings->defaultPaymentMethod();
    }

    /**
     * Builds the signed fiscalization request. Does not perform any I/O.
     */
    public function run(
        DocumentKind $kind = DocumentKind::CREATE,
        ?CorrectiveReference $corrective = null,
        ?SubseqDelivType $subseq = null,
        ?PaymentMethod $paymentMethod = null,
        ?InvoiceType $invoiceType = null,
        bool $reserveOrdinal = true,
        ?int $ordinal = null,
        ?string $issueDateTime = null,
        ?string $iic = null,
        ?string $iicSignature = null,
    ): self {
        if (!$this->settings->isConfigured()) {
            throw new RuntimeException(
                'Montenegro fiscalization is not configured. Missing: ' . implode(', ', $this->settings->missingRequiredSettings())
            );
        }

        $this->certificate = $this->settings->certificate();

        if ($this->certificate === null) {
            throw new RuntimeException('Montenegro fiscalization: no usable signing certificate was found.');
        }

        $this->kind = $kind;
        $this->corrective = $corrective;
        $this->subseq = $subseq;

        if ($issueDateTime !== null && $issueDateTime !== '') {
            $this->issueDateTime = $issueDateTime;
        }

        if ($kind === DocumentKind::ADVANCE) {
            // Specification Table 90: the ADVANCE pay method is a non-cash
            // method, so an advance invoice is not a cash invoice.
            $paymentMethod = PaymentMethod::ADVANCE;
        }

        $this->paymentMethod = $paymentMethod ?? $this->settings->defaultPaymentMethod();
        $this->invoiceType = $invoiceType ?? $this->paymentMethod->invoiceType();
        $this->badDebt = $kind === DocumentKind::BADDEBT;

        if ($this->invoiceType === InvoiceType::NONCASH
            && $this->settings->requireBuyerTin()
            && $this->buyerTaxNumber() === '') {
            throw new RuntimeException('Montenegro fiscalization: a buyer tax number is required for non-cash invoices.');
        }

        // Specification 3.6: a cash invoice cannot be issued before the daily
        // INITIAL cash deposit has been registered for the device.
        if ($this->invoiceType === InvoiceType::CASH
            && !MontenegroCashDeposit::hasInitialForToday((int) $this->invoice->company_id, $this->settings->tcrCode())) {
            throw new RuntimeException(
                'Montenegro fiscalization: register an INITIAL cash deposit for today before fiscalizing a cash invoice.'
            );
        }

        $this->totals = (new InvoiceTotalsCalculator($this->invoice))->calculate();

        if ($ordinal !== null && $ordinal > 0) {
            $this->ordinal = $ordinal;
        } else {
            $this->ordinal = $reserveOrdinal
                ? MontenegroFiscalCounter::nextOrdinal($this->invoice->company_id, (int) $this->issueDateTimeYear())
                : MontenegroFiscalCounter::peekOrdinal($this->invoice->company_id, (int) $this->issueDateTimeYear());
        }

        $this->invoiceNumber = $this->composeInvoiceNumber($this->ordinal);

        $payload = Ikof::concatenate(
            $this->settings->tin(),
            $this->issueDateTime,
            $this->invoiceNumber,
            $this->settings->businessUnitCode(),
            $this->settings->tcrCode(),
            $this->settings->softwareCode(),
            Money::decimal($this->totals->total)
        );

        // On a resend the IIC and IICSignature are reused even when the signing
        // certificate has been renewed, as required by technical specification 4.3.
        $this->ikof = ($iic !== null && $iicSignature !== null && $iic !== '' && $iicSignature !== '')
            ? new Ikof($iic, $iicSignature, $payload)
            : Ikof::generate($payload, $this->certificate->privateKey);

        $document = (new DocumentBuilder($this->invoice, $this->settings))->build(
            totals: $this->totals,
            iic: $this->ikof->iic,
            iicSignature: $this->ikof->signature,
            invoiceNumber: $this->invoiceNumber,
            ordinal: $this->ordinal,
            issueDateTime: $this->issueDateTime,
            invoiceType: $this->invoiceType,
            paymentMethod: $this->paymentMethod,
            requestUuid: $this->requestUuid,
            sendDateTime: $this->sendDateTime,
            subseq: $this->subseq,
            corrective: $this->corrective,
            isBadDebt: $this->badDebt,
            kind: $this->kind,
        );

        [$envelope, $requestElement] = Envelope::wrap($document);

        (new Signer($this->certificate->privateKey, $this->certificate->certificate))->sign($envelope, $requestElement);

        $this->signedEnvelope = $envelope->saveXML() ?: null;

        $this->assertSendable();

        $this->built = true;

        return $this;
    }

    /**
     * Local enforcement of the CIS limits from specification 3.4, so a request
     * that would be rejected (errors 1 and 11) is not sent at all.
     */
    private function assertSendable(): void
    {
        $size = strlen((string) $this->signedEnvelope);

        if ($size > 150000) {
            throw new RuntimeException(
                'Montenegro fiscalization: the request is ' . $size . ' bytes and exceeds the 150 kB message size limit.'
            );
        }

        // The TIN in the message must match the certificate, otherwise the CIS
        // rejects the message with error 37. The certificate TIN is derived
        // automatically, so only an explicit conflicting setting can fail.
        $certificateTin = $this->certificate?->taxNumber();
        $configuredTin = trim((string) $this->invoice->company->getSetting(FiscalSettings::SETTING_TIN));

        if ($certificateTin !== null && $certificateTin !== '' && $configuredTin !== '' && $configuredTin !== $certificateTin) {
            throw new RuntimeException(
                'Montenegro fiscalization: the configured TIN (' . $configuredTin . ') does not match the TIN in the signing certificate (' . $certificateTin . ').'
            );
        }

        if ($this->totals === null) {
            return;
        }

        // A taxpayer outside the VAT system must not report VAT.
        if (!$this->settings->isInVat() && $this->totals->totalVat > 0.0) {
            throw new RuntimeException(
                'Montenegro fiscalization: the company is not in the VAT system but the invoice carries VAT.'
            );
        }

        // A taxpayer in the VAT system that charges VAT must group it in SameTaxes.
        if ($this->settings->isInVat() && $this->totals->totalVat > 0.0 && $this->totals->sameTaxes === []) {
            throw new RuntimeException(
                'Montenegro fiscalization: the invoice carries VAT but no SameTaxes group was produced.'
            );
        }
    }

    public function isBuilt(): bool
    {
        return $this->built;
    }

    public function getEnvelope(): ?string
    {
        return $this->signedEnvelope;
    }

    public function getIkof(): ?Ikof
    {
        return $this->ikof;
    }

    public function getTotals(): ?InvoiceTotals
    {
        return $this->totals;
    }

    public function getKind(): DocumentKind
    {
        return $this->kind;
    }

    public function getRequestUuid(): string
    {
        return $this->requestUuid;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function getOrdinal(): int
    {
        return $this->ordinal;
    }

    public function getIssueDateTime(): string
    {
        return $this->issueDateTime;
    }

    public function getInvoiceType(): InvoiceType
    {
        return $this->invoiceType;
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getSubseqDelivType(): ?SubseqDelivType
    {
        return $this->subseq;
    }

    /**
     * Serialisable snapshot of everything that was fiscalized. Stored on the log
     * row so a QR code can be rebuilt and the payload can be audited later.
     *
     * @return array<string, mixed>
     */
    public function toLogState(): array
    {
        return [
            'iic' => $this->ikof?->iic,
            'iic_signature' => $this->ikof?->signature,
            'request_uuid' => $this->requestUuid,
            'inv_num' => $this->invoiceNumber,
            'ordinal' => $this->ordinal,
            'issue_date_time' => $this->issueDateTime,
            'send_date_time' => $this->sendDateTime,
            'invoice_type' => $this->invoiceType->value,
            'payment_method' => $this->paymentMethod->value,
            'kind' => $this->kind->value,
            'subseq_deliv_type' => $this->subseq?->value,
            'tin' => $this->settings->tin(),
            'tcr' => $this->settings->tcrCode(),
            'bu' => $this->settings->businessUnitCode(),
            'operator' => $this->settings->operatorCode(),
            'sw' => $this->settings->softwareCode(),
            'is_in_vat' => $this->settings->isInVat(),
            'test_mode' => $this->settings->testMode(),
            'mode' => $this->settings->testMode() ? 'TEST' : 'PROD',
            'total' => $this->totals?->total,
            'total_without_vat' => $this->totals?->totalWithoutVat,
            'total_vat' => $this->totals?->totalVat,
            'corrective' => $this->corrective === null ? null : [
                'iic' => $this->corrective->iic,
                'issue_date_time' => $this->corrective->issueDateTime,
                'type' => $this->corrective->type?->value,
            ],
        ];
    }

    public function qrCodeUrl(): string
    {
        return $this->settings->qrCode()->url(
            (string) ($this->ikof?->iic ?? ''),
            $this->settings->tin(),
            $this->issueDateTime,
            $this->ordinal,
            $this->settings->businessUnitCode(),
            $this->settings->tcrCode(),
            $this->settings->softwareCode(),
            Money::decimal($this->totals?->total ?? 0),
        );
    }

    /**
     * Rebuilds the verification URL for a previously logged invoice.
     */
    public function qrCodeUrlFromLog(MontenegroFiscalLog $log): string
    {
        $state = $log->request_state ?? [];

        return $this->settings->qrCode()->url(
            (string) ($log->iic ?? ($state['iic'] ?? '')),
            (string) ($state['tin'] ?? $this->settings->tin()),
            (string) ($state['issue_date_time'] ?? ''),
            (int) ($state['ordinal'] ?? 0),
            (string) ($state['bu'] ?? $this->settings->businessUnitCode()),
            (string) ($state['tcr'] ?? $this->settings->tcrCode()),
            (string) ($state['sw'] ?? $this->settings->softwareCode()),
            Money::decimal($state['total'] ?? 0),
        );
    }

    /**
     * Returns the verification QR code for a logged invoice as a PNG data URI.
     */
    public function qrCodeDataUriFromLog(MontenegroFiscalLog $log): string
    {
        return $this->settings->qrCode()->dataUri($this->qrCodeUrlFromLog($log));
    }

    /**
     * Sends the signed request to the CIS and returns the processed response.
     *
     * @return array<string, mixed>
     */
    public function send(): array
    {
        if ($this->signedEnvelope === null) {
            throw new RuntimeException('Montenegro fiscalization: run() must be called before send().');
        }

        $client = new CisClient(
            endpoint: $this->settings->endpoint(),
            soapAction: CisClient::SOAP_ACTION_REGISTER_INVOICE,
        );

        $raw = $client->send($this->signedEnvelope);

        return (new ResponseProcessor())->process($raw['body'], $raw['status']);
    }

    private function now(): string
    {
        return now($this->invoice->company->timezone()?->name ?? 'UTC')->format('Y-m-d\TH:i:sP');
    }

    private function buyerTaxNumber(): string
    {
        $client = $this->invoice->client;

        if ($client === null) {
            return '';
        }

        return trim((string) ($client->vat_number ?: $client->id_number));
    }

    private function issueDateTimeYear(): string
    {
        return substr($this->issueDateTime, 0, 4);
    }

    /**
     * v5 section 3.7.1.11: the invoice number is
     * BusinUnitCode / ordinal / year / TCRCode, for example
     * pp123pp123/9934/2019/ab123ab123.
     */
    private function composeInvoiceNumber(int $ordinal): string
    {
        return $this->settings->businessUnitCode()
            . '/' . $ordinal
            . '/' . $this->issueDateTimeYear()
            . '/' . $this->settings->tcrCode();
    }
}
