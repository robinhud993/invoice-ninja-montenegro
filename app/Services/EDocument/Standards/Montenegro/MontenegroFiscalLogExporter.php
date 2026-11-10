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

use App\Models\Company;
use App\Models\MontenegroFiscalLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * MontenegroFiscalLogExporter
 *
 * Builds the fiscal journal export required for tax inspection: one row per
 * fiscalization attempt, with the IKOF (IIC), the CIS confirmation (JIKR), the
 * assigned fiscal number and the totals. Distinct from MontenegroExporter,
 * which produces signed XML messages for offline delivery.
 */
class MontenegroFiscalLogExporter
{
    /**
     * Export column order. The same order is used for CSV headers and rows.
     *
     * @var array<int, string>
     */
    public const COLUMNS = [
        'id',
        'entity_type',
        'entity_id',
        'invoice_number',
        'document_type',
        'kind',
        'status',
        'iic',
        'jikr',
        'total',
        'tin',
        'tcr_code',
        'business_unit_code',
        'operator_code',
        'software_code',
        'ordinal',
        'issue_date_time',
        'subseq_deliv_type',
        'error_code',
        'error_message',
        'sent_at',
        'created_at',
    ];

    public function __construct(
        private Company $company,
        private ?CarbonInterface $from = null,
        private ?CarbonInterface $to = null,
        private ?string $status = null,
        private ?string $kind = null,
        private ?int $invoiceId = null,
        private ?int $creditId = null,
        private string $order = 'asc',
    ) {}

    public function query(): Builder
    {
        return MontenegroFiscalLog::query()
            ->where('company_id', $this->company->id)
            ->when($this->from, fn (Builder $query): Builder => $query->where('created_at', '>=', $this->from))
            ->when($this->to, fn (Builder $query): Builder => $query->where('created_at', '<=', $this->to))
            ->when($this->status, fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->kind, fn (Builder $query): Builder => $query->where('kind', $this->kind))
            ->when($this->invoiceId, fn (Builder $query): Builder => $query->where('invoice_id', $this->invoiceId))
            ->when($this->creditId, fn (Builder $query): Builder => $query->where('credit_id', $this->creditId))
            ->orderBy('id', $this->order === 'desc' ? 'desc' : 'asc');
    }

    /**
     * Full detail for the in-app viewer, including the raw CIS request payload
     * and the raw Tax Administration response. Kept out of the CSV export, which
     * stays flat and small.
     *
     * @return array<string, mixed>
     */
    public function detail(MontenegroFiscalLog $log): array
    {
        return array_merge($this->row($log), [
            'response' => $log->response,
            'request_state' => $log->request_state,
        ]);
    }

    /**
     * Writes the journal as CSV to the supplied stream and returns the number of
     * data rows written.
     *
     * @param resource $stream
     */
    public function writeCsv($stream): int
    {
        fputcsv($stream, self::COLUMNS);

        $count = 0;

        $this->query()->lazy(500)->each(function (MontenegroFiscalLog $log) use ($stream, &$count): void {
            fputcsv($stream, array_values($this->row($log)));
            $count++;
        });

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(int $limit = 50000): array
    {
        return $this->query()
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (MontenegroFiscalLog $log): array => $this->row($log))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function row(MontenegroFiscalLog $log): array
    {
        $state = $log->request_state ?? [];

        return [
            'id' => $log->id,
            'entity_type' => $log->credit_id ? 'credit' : 'invoice',
            'entity_id' => $log->credit_id ?: $log->invoice_id,
            'invoice_number' => $state['inv_num'] ?? null,
            'document_type' => $log->document_type,
            'kind' => $log->kind,
            'status' => $log->status,
            'iic' => $log->iic,
            'jikr' => $log->jikr,
            'total' => $log->total,
            'tin' => $state['tin'] ?? null,
            'tcr_code' => $state['tcr'] ?? null,
            'business_unit_code' => $state['bu'] ?? null,
            'operator_code' => $state['operator'] ?? null,
            'software_code' => $state['sw'] ?? null,
            'ordinal' => $state['ordinal'] ?? null,
            'issue_date_time' => $state['issue_date_time'] ?? null,
            'subseq_deliv_type' => $log->subseq_deliv_type,
            'error_code' => $log->error_code,
            'error_message' => $log->error_message,
            'sent_at' => $log->sent_at?->toIso8601String(),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    public function filename(string $extension): string
    {
        $name = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string) ($this->company->settings->name ?? $this->company->id));

        return 'montenegro-fiscal-journal-' . $name . '-' . now()->format('Ymd-His') . '.' . $extension;
    }
}
