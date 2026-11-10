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

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\MontenegroCashDeposit;
use App\Models\MontenegroEnu;
use App\Models\MontenegroFiscalLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\EInvoice\Montenegro\SetupRequest;
use App\Http\Requests\EInvoice\Montenegro\StatusRequest;
use App\Http\Requests\EInvoice\Montenegro\DisconnectRequest;
use App\Http\Requests\EInvoice\Montenegro\RegisterTcrRequest;
use App\Http\Requests\EInvoice\Montenegro\CashDepositRequest;
use App\Http\Requests\EInvoice\Montenegro\ExportRequest;
use App\Http\Requests\EInvoice\Montenegro\SaveEnuRequest;
use App\Http\Requests\EInvoice\Montenegro\DeleteEnuRequest;
use App\Http\Requests\EInvoice\Montenegro\FiscalLogListRequest;
use App\Http\Requests\EInvoice\Montenegro\FiscalLogExportRequest;
use App\Http\Requests\EInvoice\Montenegro\FiscalReportRequest;
use App\Services\EDocument\Standards\Montenegro\CashDeposit;
use App\Services\EDocument\Standards\Montenegro\TcrRegistration;
use App\Services\EDocument\Standards\Montenegro\MontenegroExporter;
use App\Services\EDocument\Standards\Montenegro\MontenegroFiscalLogExporter;
use App\Services\EDocument\Standards\Montenegro\MontenegroFiscalReport;
use App\Services\EDocument\Standards\Montenegro\Certificate;
use App\Services\EDocument\Standards\Montenegro\FiscalSettings;
use App\Services\EDocument\Standards\Montenegro\Enums\TcrType;
use App\Services\EDocument\Standards\Montenegro\Enums\CashDepositOperation;
use App\Services\EDocument\Standards\Montenegro\Enums\SubseqDelivType;

/**
 * MontenegroFiscalizationController
 *
 * Organization level setup for Montenegro EFI (elektronska fiskalizacija):
 * signing certificate upload and replacement, certificate passphrase, and
 * production or test environment selection, together with the CIS assigned
 * registration codes.
 */
class MontenegroFiscalizationController extends BaseController
{
    use \App\Utils\Traits\MakesHash;
    /**
     * Stores or replaces the Montenegro EFI configuration for the current company.
     */
    public function setup(SetupRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();
        $settings = $company->settings;

        if ($request->boolean('remove_certificate')) {
            $company->e_invoice_certificate = null;
            $company->e_invoice_certificate_passphrase = null;
        }

        // An empty passphrase means "not supplied", so the stored passphrase is
        // used. This avoids an empty value overriding a saved passphrase.
        $passphrase = $request->filled('certificate_passphrase')
            ? (string) $request->input('certificate_passphrase')
            : $company->getSslPassPhrase();

        if ($request->hasFile('certificate')) {
            $raw = $request->file('certificate')->get();

            if (Certificate::fromBlob($raw, $passphrase) === null) {
                return response()->json([
                    'message' => 'The certificate could not be read. Check the file format (PKCS#12 or PEM) and the passphrase.',
                ], 422);
            }

            $company->e_invoice_certificate = base64_encode($raw);
        } elseif ($request->has('certificate_passphrase') && $company->e_invoice_certificate) {
            $raw = (string) base64_decode($company->e_invoice_certificate, true);

            if ($raw === '' || Certificate::fromBlob($raw, $passphrase) === null) {
                return response()->json([
                    'message' => 'The stored certificate could not be opened with the supplied passphrase.',
                ], 422);
            }
        }

        if ($request->has('certificate_passphrase')) {
            $company->e_invoice_certificate_passphrase = $request->input('certificate_passphrase');
        }

        $stringSettings = [
            'montenegro_tin' => 'tin',
            'montenegro_tcr_code' => 'tcr_code',
            'montenegro_business_unit_code' => 'business_unit_code',
            'montenegro_operator_code' => 'operator_code',
            'montenegro_software_code' => 'software_code',
            'montenegro_maintainer_code' => 'maintainer_code',
            'montenegro_invoice_type' => 'invoice_type',
            'montenegro_payment_method' => 'payment_method',
            'montenegro_advance_marker' => 'advance_marker',
            'montenegro_bank_account' => 'bank_account',
            'montenegro_comp_card' => 'comp_card',
            'montenegro_self_iss_type' => 'self_iss_type',
        ];

        foreach ($stringSettings as $setting => $field) {
            if ($request->has($field) && $request->input($field) !== null) {
                $settings->{$setting} = $request->input($field);
            }
        }

        $booleanSettings = [
            'montenegro_is_in_vat' => 'is_in_vat',
            'montenegro_test_mode' => 'test_mode',
            'montenegro_reverse_charge' => 'reverse_charge',
            'montenegro_auto_fiscalize' => 'auto_fiscalize',
            'montenegro_require_buyer_tin' => 'require_buyer_tin',
            'montenegro_simplified_invoice' => 'simplified_invoice',
        ];

        foreach ($booleanSettings as $setting => $field) {
            if ($request->has($field)) {
                $settings->{$setting} = $request->boolean($field);
            }
        }

        if ($request->has('enable')) {
            $settings->e_invoice_type = $request->boolean('enable') ? 'MONTENEGRO' : 'EN16931';
            $settings->enable_e_invoice = $request->boolean('enable');
        } elseif ($settings->e_invoice_type !== 'MONTENEGRO') {
            // Calling setup connects the standard unless explicitly disabled.
            $settings->e_invoice_type = 'MONTENEGRO';
            $settings->enable_e_invoice = true;
        }

        $company->settings = $settings;
        $company->save();

        return response()->json($this->statusPayload($company->fresh()));
    }

    /**
     * Returns the current Montenegro EFI configuration status. Never returns secrets.
     */
    public function status(StatusRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return response()->json($this->statusPayload($user->company()));
    }

    /**
     * Disconnects the Montenegro standard for the current company.
     */
    public function disconnect(DisconnectRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();

        $settings = $company->settings;
        $settings->e_invoice_type = 'EN16931';
        $settings->enable_e_invoice = false;
        $company->settings = $settings;

        if ($request->boolean('remove_certificate')) {
            $company->e_invoice_certificate = null;
            $company->e_invoice_certificate_passphrase = null;
        }

        $company->save();

        return response()->json($this->statusPayload($company->fresh()));
    }

    /**
     * Lists the registered electronic fiscal devices (ENU / TCR) of the company.
     */
    public function enus(StatusRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();

        return response()->json([
            'data' => MontenegroEnu::query()
                ->where('company_id', $company->id)
                ->orderBy('id')
                ->get()
                ->map(fn (MontenegroEnu $enu): array => $this->enuPayload($enu))
                ->values(),
        ]);
    }

    /**
     * Creates or updates an ENU in the company registry.
     */
    public function saveEnu(SaveEnuRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();

        $key = $this->resolveEnuKey($request->input('id'));

        $enu = ($key && $key !== 'Invalid Primary Key')
            ? MontenegroEnu::query()->where('company_id', $company->id)->find($key)
            : null;

        if (! $enu) {
            $enu = new MontenegroEnu(['company_id' => $company->id]);
        }

        $enu->fill([
            'name' => $request->input('name'),
            'device_type' => $request->input('device_type'),
            'sales_type' => $request->input('sales_type'),
            'payment_method' => $request->input('payment_method'),
            'tcr_internal_id' => $request->input('tcr_internal_id'),
            'tcr_code' => $request->input('tcr_code'),
            'business_unit_code' => $request->input('business_unit_code'),
            'operator_code' => $request->input('operator_code'),
            'software_code' => $request->input('software_code'),
            'maintainer_code' => $request->input('maintainer_code'),
            'valid_from' => $request->input('valid_from'),
            'valid_to' => $request->input('valid_to'),
        ]);

        if ($request->has('is_default')) {
            $enu->is_default = $request->boolean('is_default');
        }

        if ($request->has('is_active')) {
            $enu->is_active = $request->boolean('is_active');
        }

        try {
            $enu->save();
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'An ENU with this TCR code already exists.'], 422);
        }

        if ($enu->is_default) {
            MontenegroEnu::query()
                ->where('company_id', $company->id)
                ->where('id', '!=', $enu->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['data' => $this->enuPayload($enu->fresh())]);
    }

    /**
     * Removes an ENU from the company registry.
     */
    public function deleteEnu(DeleteEnuRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();

        $key = $this->resolveEnuKey($request->input('id'));

        $enu = ($key && $key !== 'Invalid Primary Key')
            ? MontenegroEnu::query()->where('company_id', $company->id)->find($key)
            : null;

        if (! $enu) {
            return response()->json(['message' => 'The ENU could not be found.'], 404);
        }

        $enu->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Accepts either the hashed id returned by this API or a plain numeric id.
     */
    private function resolveEnuKey(mixed $value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit((string) $value)) {
            return (int) $value;
        }

        return $this->decodePrimaryKey((string) $value, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function enuPayload(MontenegroEnu $enu): array
    {
        return [
            'id' => $this->encodePrimaryKey($enu->id),
            'name' => $enu->name,
            'tcr_code' => $enu->tcr_code,
            'business_unit_code' => $enu->business_unit_code,
            'operator_code' => $enu->operator_code,
            'software_code' => $enu->software_code,
            'maintainer_code' => $enu->maintainer_code,
            'is_default' => (bool) $enu->is_default,
            'is_active' => (bool) $enu->is_active,
        ];
    }

    /**
     * Exports signed fiscalization messages for invoices that have not been
     * fiscalized, for manual delivery to the Tax Administration.
     */
    public function export(ExportRequest $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();

        $subseq = SubseqDelivType::tryFrom(strtoupper((string) $request->input('subsequent_delivery', SubseqDelivType::NOINTERNET->value)))
            ?? SubseqDelivType::NOINTERNET;

        $invoices = $this->invoicesForExport($request, $company);

        if ($invoices->isEmpty()) {
            return response()->json(['message' => 'No unfiscalized invoices were found to export.'], 422);
        }

        try {
            $result = (new MontenegroExporter($company))->export($invoices, $subseq);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->download($result['path'], basename($result['path']))->deleteFileAfterSend(true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Invoice>
     */
    private function invoicesForExport(ExportRequest $request, Company $company)
    {
        if ($request->filled('invoice_ids')) {
            $ids = collect(explode(',', (string) $request->input('invoice_ids')))
                ->map(fn ($value) => $this->decodePrimaryKey(trim($value), true))
                ->filter(fn ($value) => $value && $value !== 'Invalid Primary Key')
                ->map(fn ($value) => (int) $value)
                ->all();

            return \App\Models\Invoice::query()
                ->where('company_id', $company->id)
                ->whereIn('id', $ids)
                ->where('is_deleted', false)
                ->orderBy('id')
                ->get();
        }

        return \App\Models\Invoice::query()
            ->where('company_id', $company->id)
            ->whereIn('status_id', [\App\Models\Invoice::STATUS_SENT, \App\Models\Invoice::STATUS_PARTIAL, \App\Models\Invoice::STATUS_PAID])
            ->where('is_deleted', false)
            ->whereDoesntHave('montenegro_fiscal_logs', fn ($query) => $query->whereNotNull('jikr'))
            ->orderBy('id')
            ->limit(500)
            ->get();
    }

    /**
     * Registers an electronic fiscal device (ENU / TCR) with the CIS and stores
     * the returned TCR code as the company ENU code.
     */
    public function registerTcr(RegisterTcrRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();
        $fiscal = new FiscalSettings($company);

        $type = TcrType::tryFrom(strtoupper((string) $request->input('type', TcrType::REGULAR->value))) ?? TcrType::REGULAR;

        try {
            $response = (new TcrRegistration($fiscal))->register(
                businessUnitCode: (string) ($request->input('business_unit_code') ?: $fiscal->businessUnitCode()),
                tcrInternalId: (string) $request->input('tcr_internal_id'),
                softwareCode: $request->input('software_code'),
                maintainerCode: $request->input('maintainer_code'),
                validFrom: $request->input('valid_from'),
                validTo: $request->input('valid_to'),
                type: $type,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($response['success'] ?? false) {
            $settings = $company->settings;
            $settings->montenegro_tcr_code = (string) $response['code'];
            $company->settings = $settings;
            $company->save();
        }

        return response()->json($response, ($response['success'] ?? false) ? 200 : 422);
    }

    /**
     * Reports a cash register deposit or withdrawal to the CIS.
     */
    public function cashDeposit(CashDepositRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();
        $fiscal = new FiscalSettings($company);

        $tcrCode = (string) ($request->input('tcr_code') ?: $fiscal->tcrCode());

        if ($tcrCode === '') {
            return response()->json(['message' => 'The ENU (TCR) code is not configured.'], 422);
        }

        $operation = CashDepositOperation::tryFrom(strtoupper((string) $request->input('operation')))
            ?? CashDepositOperation::INITIAL;

        $changeDateTime = $request->input('change_datetime')
            ? Carbon::parse($request->input('change_datetime'))
            : now();

        $record = MontenegroCashDeposit::create([
            'company_id' => $company->id,
            'tcr_code' => $tcrCode,
            'operation' => $operation->value,
            'cash_amt' => (float) $request->input('cash_amount', 0),
            'change_datetime' => $changeDateTime,
            'status' => MontenegroCashDeposit::STATUS_PENDING,
        ]);

        try {
            $response = (new CashDeposit($fiscal))->submit(
                tcrCode: $tcrCode,
                operation: $operation,
                cashAmount: $request->input('cash_amount', 0),
                changeDateTime: $request->input('change_datetime'),
            );
        } catch (\Throwable $e) {
            $record->fill([
                'status' => MontenegroCashDeposit::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ])->save();

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $success = (bool) ($response['success'] ?? false);

        $record->fill([
            'status' => $success ? MontenegroCashDeposit::STATUS_SENT : MontenegroCashDeposit::STATUS_FAILED,
            'fcdc' => $response['code'] ?? null,
            'error_code' => $response['error_code'] ?? null,
            'error_message' => $response['message'] ?? null,
            'response' => $response,
            'sent_at' => $success ? now() : null,
        ])->save();

        return response()->json($response, $success ? 200 : 422);
    }

    /**
     * Lists the fiscal journal of the company: one row per fiscalization
     * attempt, filterable by date range, status and document kind.
     */
    public function fiscalLogs(FiscalLogListRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();

        $exporter = $this->fiscalLogExporter($request, $company);

        $paginator = $exporter->query()->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (MontenegroFiscalLog $log): array => $this->logPayload($exporter, $log))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Exports the fiscal journal for tax inspection. CSV streams the full result
     * set; JSON is bounded by the configured export limit.
     */
    public function exportFiscalLogs(FiscalLogExportRequest $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();

        $exporter = $this->fiscalLogExporter($request, $company);

        if (strtolower((string) $request->input('format', 'csv')) === 'json') {
            return response()->json([
                'data' => $exporter->toArray((int) config('services.montenegro.fiscal_log_export_limit', 50000)),
            ]);
        }

        return response()->streamDownload(function () use ($exporter): void {
            $stream = fopen('php://output', 'w');

            if ($stream !== false) {
                $exporter->writeCsv($stream);
                fclose($stream);
            }
        }, $exporter->filename('csv'), ['Content-Type' => 'text/csv']);
    }

    /**
     * Fiscalization reporting: success rates, amounts, breakdowns, the open
     * backlog and an optional day/month timeline for the company.
     */
    public function report(FiscalReportRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();

        $report = (new MontenegroFiscalReport(
            company: $company,
            from: $request->filled('date_from') ? Carbon::parse((string) $request->input('date_from'))->startOfDay() : null,
            to: $request->filled('date_to') ? Carbon::parse((string) $request->input('date_to'))->endOfDay() : null,
            groupBy: $request->filled('group_by') ? (string) $request->input('group_by') : null,
            status: $request->filled('status') ? (string) $request->input('status') : null,
            kind: $request->filled('kind') ? (string) $request->input('kind') : null,
        ))->build();

        return response()->json($report);
    }

    private function fiscalLogExporter(
        FiscalLogListRequest|FiscalLogExportRequest $request,
        Company $company,
    ): MontenegroFiscalLogExporter {
        $invoiceId = $request->filled('invoice_id')
            ? $this->decodePrimaryKey((string) $request->input('invoice_id'))
            : null;
        $creditId = $request->filled('credit_id')
            ? $this->decodePrimaryKey((string) $request->input('credit_id'))
            : null;

        return new MontenegroFiscalLogExporter(
            company: $company,
            from: $request->filled('date_from') ? Carbon::parse((string) $request->input('date_from'))->startOfDay() : null,
            to: $request->filled('date_to') ? Carbon::parse((string) $request->input('date_to'))->endOfDay() : null,
            status: $request->filled('status') ? (string) $request->input('status') : null,
            kind: $request->filled('kind') ? (string) $request->input('kind') : null,
            invoiceId: is_numeric($invoiceId) ? (int) $invoiceId : null,
            creditId: is_numeric($creditId) ? (int) $creditId : null,
            order: $request->filled('order')
                ? (string) $request->input('order')
                : ($request instanceof FiscalLogListRequest ? 'desc' : 'asc'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function logPayload(MontenegroFiscalLogExporter $exporter, MontenegroFiscalLog $log): array
    {
        return array_merge($exporter->detail($log), [
            'hashed_id' => $this->encodePrimaryKey($log->id),
            'fiscalized' => $log->isFiscalized(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(Company $company): array
    {
        $fiscal = new FiscalSettings($company);
        $certificate = $fiscal->certificate();
        $certificateStatus = $certificate?->status();

        return [
            'connected' => $company->montenegroEnabled(),
            'configured' => $fiscal->isConfigured(),
            'missing' => $fiscal->missingRequiredSettings(),
            'has_certificate' => $company->e_invoice_certificate !== null,
            'certificate_error' => ($company->e_invoice_certificate !== null && $certificate === null)
                ? 'The stored certificate could not be read. Check the file format and the passphrase.'
                : null,
            'certificate_valid' => (bool) ($certificateStatus['valid'] ?? false),
            'certificate' => $certificateStatus,
            'certificate_tin' => $certificateStatus['tin'] ?? null,
            'certificate_expires_at' => $certificateStatus['expires_at'] ?? null,
            'certificate_days_remaining' => $certificateStatus['days_remaining'] ?? null,
            'certificate_expired' => (bool) ($certificateStatus['expired'] ?? false),
            'certificate_expiring_soon' => (bool) ($certificateStatus['expiring_soon'] ?? false),
            'certificate_warning_days' => Certificate::WARNING_DAYS,
            'test_mode' => $fiscal->testMode(),
            'endpoint' => $fiscal->endpoint(),
            'settings' => [
                'tin' => $fiscal->tin(),
                'tcr_code' => $fiscal->tcrCode(),
                'business_unit_code' => $fiscal->businessUnitCode(),
                'operator_code' => $fiscal->operatorCode(),
                'software_code' => $fiscal->softwareCode(),
                'maintainer_code' => $fiscal->maintainerCode(),
                'is_in_vat' => $fiscal->isInVat(),
                'invoice_type' => $fiscal->defaultInvoiceType()->value,
                'payment_method' => $fiscal->defaultPaymentMethod()->value,
                'advance_marker' => $fiscal->advanceMarker(),
                'auto_fiscalize' => $fiscal->autoFiscalize(),
                'require_buyer_tin' => $fiscal->requireBuyerTin(),
                'simplified_invoice' => $fiscal->simplifiedInvoice(),
            ],
        ];
    }
}
