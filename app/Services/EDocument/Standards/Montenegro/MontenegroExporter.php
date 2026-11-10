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

use DOMDocument;
use DOMXPath;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MontenegroFiscalLog;
use RuntimeException;
use ZipArchive;
use App\Services\EDocument\Standards\Montenegro\Enums\DocumentKind;
use App\Services\EDocument\Standards\Montenegro\Enums\SubseqDelivType;
use App\Services\EDocument\Standards\Montenegro\Soap\Envelope;

/**
 * MontenegroExporter
 *
 * Builds a ZIP of signed RegisterInvoiceRequest messages for invoices that have
 * not been fiscalized, so they can be delivered manually to the Tax
 * Administration when the ENU could not reach the CIS.
 *
 * The exporter is ordinal safe: when an unfiscalized fiscal record already
 * exists the same ordinal, issue datetime and IKOF are reused, so exporting
 * never consumes a second fiscal number.
 */
class MontenegroExporter
{
    public function __construct(private Company $company) {}

    /**
     * @param iterable<int, Invoice> $invoices
     *
     * @return array{path: string, exported: int, failed: array<int, string>}
     */
    public function export(iterable $invoices, ?SubseqDelivType $subseq = null): array
    {
        $subseq ??= SubseqDelivType::NOINTERNET;

        $entries = [];
        $failed = [];

        foreach ($invoices as $invoice) {
            try {
                $entries[] = $this->buildMessage($invoice, $subseq);
            } catch (\Throwable $e) {
                $failed[] = ($invoice->number ?: (string) $invoice->id) . ': ' . $e->getMessage();
            }
        }

        // Specification 3.7.6: the archive is named
        // <yyyyMMddHHmmSS>_<TCRCode>_request.zip.
        $tcr = (new FiscalSettings($this->company))->tcrCode() ?: 'unknown';
        $path = storage_path('app/' . now()->format('YmdHis') . '_' . $tcr . '_request.zip');

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the export archive.');
        }

        foreach ($entries as [$name, $xml]) {
            $zip->addFromString($name, $xml);
        }

        $zip->addFromString('manifest.txt', implode(PHP_EOL, array_merge([
            'created_at_utc=' . now()->toIso8601String(),
            'company=' . ($this->company->settings->name ?? $this->company->id),
            'subsequent_delivery=' . $subseq->value,
            'exported=' . count($entries),
            'failed=' . count($failed),
        ], $failed)) . PHP_EOL);

        $zip->close();

        return ['path' => $path, 'exported' => count($entries), 'failed' => $failed];
    }

    /**
     * @return array{0: string, 1: string} the specification file name and the Body content
     */
    private function buildMessage(Invoice $invoice, SubseqDelivType $subseq): array
    {
        $open_log = $invoice->montenegro_fiscal_logs()->whereNull('jikr')->first();
        $state = $open_log?->request_state ?? [];

        $montenegro = new Montenegro($invoice);

        $montenegro->run(
            kind: DocumentKind::tryFrom($open_log->kind ?? DocumentKind::CREATE->value) ?? DocumentKind::CREATE,
            subseq: $subseq,
            reserveOrdinal: $open_log === null,
            ordinal: isset($state['ordinal']) ? (int) $state['ordinal'] : null,
            issueDateTime: $state['issue_date_time'] ?? null,
            iic: $state['iic'] ?? null,
            iicSignature: $state['iic_signature'] ?? null,
        );

        $xml = (string) $montenegro->getEnvelope();

        // A first export reserves the ordinal, so the fiscal record is persisted
        // to keep the identity stable across repeated exports.
        if ($open_log === null) {
            MontenegroFiscalLog::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'kind' => DocumentKind::CREATE->value,
                'status' => MontenegroFiscalLog::STATUS_PENDING,
                'subseq_deliv_type' => $subseq->value,
                'iic' => $montenegro->getIkof()?->iic,
                'request_uuid' => $montenegro->getRequestUuid(),
                'total' => $montenegro->getTotals()?->total ?? 0,
                'request_state' => $montenegro->toLogState(),
            ]);
        }

        return [$this->fileName($montenegro->toLogState()), $this->bodyContent($xml)];
    }

    /**
     * Specification 3.7.6: <yyyyMMddHHmmSS>_<TCRCode>_<IIC>_request.xml.
     *
     * @param array<string, mixed> $state
     */
    private function fileName(array $state): string
    {
        $stamp = !empty($state['send_date_time'])
            ? \Carbon\Carbon::parse((string) $state['send_date_time'])->format('YmdHis')
            : now()->format('YmdHis');

        $tcr = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string) ($state['tcr'] ?? 'unknown'));
        $iic = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string) ($state['iic'] ?? 'unknown'));

        return $stamp . '_' . $tcr . '_' . $iic . '_request.xml';
    }

    /**
     * Specification 3.7.6: only the SOAP Body content is stored, so the
     * signature stays valid when the file is delivered to the Tax
     * Administration.
     */
    private function bodyContent(string $soapXml): string
    {
        $doc = new DOMDocument();

        if (!@$doc->loadXML($soapXml)) {
            return $soapXml;
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('soap', Envelope::NS);

        $nodes = $xpath->query('/soap:Envelope/soap:Body/*');

        if ($nodes === false || $nodes->length === 0) {
            return $soapXml;
        }

        return $doc->saveXML($nodes->item(0)) ?: $soapXml;
    }
}
