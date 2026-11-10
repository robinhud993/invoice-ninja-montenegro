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

namespace App\Services\EDocument\Standards\Montenegro\Jobs;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Credit;
use App\Models\SystemLog;
use App\Models\MontenegroFiscalLog;
use App\Libraries\MultiDB;
use App\Jobs\Util\SystemLogger;
use Illuminate\Bus\Queueable;
use App\Services\EDocument\Standards\Montenegro\Montenegro;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use App\Services\EDocument\Standards\Montenegro\CorrectiveReference;
use App\Services\EDocument\Standards\Montenegro\Enums\CorrectiveType;
use App\Services\EDocument\Standards\Montenegro\Enums\DocumentKind;
use App\Services\EDocument\Standards\Montenegro\Enums\InvoiceType;
use App\Services\EDocument\Standards\Montenegro\Enums\PaymentMethod;
use App\Services\EDocument\Standards\Montenegro\Enums\SubseqDelivType;

/**
 * SendToCis
 *
 * Fiscalizes an invoice against the Montenegro EFI Central Information System.
 *
 * The job is idempotent for resends: on the first attempt it reserves the EFI
 * invoice ordinal and stores the IKOF state on the log row. Transient failures
 * are released back onto the queue with the log id so the same ordinal, issue
 * date and IKOF are reused and only the message is re-signed with the
 * subsequent delivery flag set.
 */
class SendToCis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 5;

    public $deleteWhenMissingModels = true;

    /**
     * @param array{iic: string, issue_date_time: string, type: string}|null $corrective
     */
    public function __construct(
        private int $invoice_id,
        private Company $company,
        private string $kind = 'create',
        private ?array $corrective = null,
        private ?string $subseq = null,
        private ?string $payment_method = null,
        private ?int $log_id = null,
        private string $entity_type = 'invoice',
    ) {}

    public function backoff(): array
    {
        return [5, 30, 120, 600, 3600];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("send_to_cis_{$this->company->company_key}"))->releaseAfter(30)->expireAfter(60)];
    }

    public function handle(): void
    {
        MultiDB::setDB($this->company->db);

        $invoice = $this->entity_type === 'credit'
            ? Credit::withTrashed()->find($this->invoice_id)
            : Invoice::withTrashed()->find($this->invoice_id);

        if (!$invoice) {
            return;
        }

        $invoice = $invoice->service()->markSent()->save();

        $kind = DocumentKind::tryFrom($this->kind) ?? DocumentKind::CREATE;
        $subseq = SubseqDelivType::fromSetting($this->subseq);

        $corrective = $this->corrective === null ? null : new CorrectiveReference(
            iic: (string) ($this->corrective['iic'] ?? ''),
            issueDateTime: (string) ($this->corrective['issue_date_time'] ?? ''),
                type: CorrectiveType::tryFrom((string) ($this->corrective['type'] ?? '')) ?? CorrectiveType::CORRECTIVE,
        );

        $log = $this->log_id === null ? null : MontenegroFiscalLog::find($this->log_id);
        $state = $log->request_state ?? [];

        // A resend must reproduce the original document exactly, so the payment
        // method and invoice type are restored from the stored state.
        $paymentMethod = null;

        if ($this->payment_method !== null) {
            $paymentMethod = PaymentMethod::tryFrom(strtoupper($this->payment_method));
        } elseif (isset($state['payment_method'])) {
            $paymentMethod = PaymentMethod::tryFrom((string) $state['payment_method']);
        }

        $invoiceType = isset($state['invoice_type']) ? InvoiceType::tryFrom((string) $state['invoice_type']) : null;

        try {
            $montenegro = new Montenegro($invoice);

            $montenegro->run(
                kind: $kind,
                corrective: $corrective,
                subseq: $subseq,
                paymentMethod: $paymentMethod,
                invoiceType: $invoiceType,
                reserveOrdinal: $log === null,
                ordinal: isset($state['ordinal']) ? (int) $state['ordinal'] : null,
                issueDateTime: $state['issue_date_time'] ?? null,
                iic: $state['iic'] ?? null,
                iicSignature: $state['iic_signature'] ?? null,
            );
        } catch (\Throwable $e) {
            // A build or configuration failure is terminal, but it must be
            // visible: record a failed log and mark the invoice, otherwise the
            // document stays "pending" forever with no trace.
            $this->recordBuildFailure($invoice, $log, $e);

            return;
        }

        $log = $this->persistLog($invoice, $montenegro, $kind, $corrective, $subseq, $log);

        $response = $montenegro->send();

        $this->applyResponse($invoice, $log, $response);
    }

    private function persistLog(
        Invoice $invoice,
        Montenegro $montenegro,
        DocumentKind $kind,
        ?CorrectiveReference $corrective,
        ?SubseqDelivType $subseq,
        ?MontenegroFiscalLog $log,
    ): MontenegroFiscalLog {
        $attributes = [
            'kind' => $kind->value,
            'iic' => $montenegro->getIkof()?->iic,
            'document_type' => $corrective?->type?->value,
            'subseq_deliv_type' => $subseq?->value,
            'request_uuid' => $montenegro->getRequestUuid(),
            'total' => $montenegro->getTotals()?->total ?? 0,
            'request_state' => $montenegro->toLogState(),
        ];

        if ($log === null) {
            return MontenegroFiscalLog::create($attributes + [
                'company_id' => $invoice->company_id,
                'invoice_id' => $this->entity_type === 'credit' ? null : $invoice->id,
                'credit_id' => $this->entity_type === 'credit' ? $invoice->id : null,
                'client_id' => $invoice->client_id,
                'status' => MontenegroFiscalLog::STATUS_PENDING,
            ]);
        }

        $log->fill($attributes + ['attempts' => $log->attempts + 1])->save();

        return $log;
    }

    /**
     * Records a terminal build or configuration failure so it is visible in the
     * fiscalization logs and the invoice does not stay "pending" forever.
     */
    private function recordBuildFailure(Invoice|Credit $invoice, ?MontenegroFiscalLog $log, \Throwable $e): void
    {
        $attributes = [
            'status' => MontenegroFiscalLog::STATUS_FAILED,
            'error_code' => null,
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
        ];

        if ($log) {
            $log->fill($attributes)->save();
        } else {
            MontenegroFiscalLog::create($attributes + [
                'company_id' => $invoice->company_id,
                'invoice_id' => $this->entity_type === 'credit' ? null : $invoice->id,
                'credit_id' => $this->entity_type === 'credit' ? $invoice->id : null,
                'client_id' => $invoice->client_id,
                'kind' => $this->kind,
                'iic' => null,
                'total' => 0,
                'request_state' => [],
            ]);
        }

        if (($invoice->backup->e_invoice_status ?? null) !== 'sent') {
            $invoice->backup->e_invoice_status = 'failed';
            $invoice->saveQuietly();
        }

        nlog('MONTENEGRO FISCALIZATION ERROR: ' . $e->getMessage());
    }

    /**
     * @param array<string, mixed> $response
     */
    private function applyResponse(Invoice $invoice, MontenegroFiscalLog $log, array $response): void
    {
        if ($response['success'] ?? false) {
            $log->fill([
                'status' => MontenegroFiscalLog::STATUS_SENT,
                'jikr' => $response['jikr'] ?? null,
                'response_uuid' => $response['response_uuid'] ?? null,
                'error_code' => null,
                'error_message' => null,
                'response' => $response,
                'sent_at' => now(),
            ])->save();

            $invoice->backup->guid = (string) $response['jikr'];
            $invoice->backup->e_invoice_status = 'sent';
            $invoice->saveQuietly();

            $this->writeActivity($invoice, Activity::MONTENEGRO_INVOICE_SENT);
            $this->systemLog($invoice, $response, SystemLog::EVENT_MONTENEGRO_SUCCESS, $this->systemLogType($log));

            return;
        }

        // A validation fault from the CIS is terminal: retrying the same
        // payload will not succeed. Transient transport or server errors stay
        // retryable and are eligible for subsequent delivery.
        $terminal = ($response['fault'] ?? false) && (int) ($response['error_code'] ?? 0) < 900;

        $log->fill([
            'status' => $terminal ? MontenegroFiscalLog::STATUS_REJECTED : MontenegroFiscalLog::STATUS_FAILED,
            'error_code' => $response['error_code'] ?? null,
            'error_message' => $response['message'] ?? null,
            'response' => $response,
        ])->save();

        $invoice->backup->e_invoice_status = $terminal ? 'rejected' : 'failed';
        $invoice->saveQuietly();

        $this->writeActivity($invoice, Activity::MONTENEGRO_INVOICE_SENT_FAILURE, (string) ($response['message'] ?? ''));
        $this->systemLog($invoice, $response, SystemLog::EVENT_MONTENEGRO_FAILURE, $this->systemLogType($log));

        if ($this->isTransient($response) && $this->attempts() < $this->tries) {
            $this->log_id = $log->id;
            $this->subseq ??= SubseqDelivType::SERVICE->value;

            $delay = $this->backoff()[$this->attempts() - 1] ?? 3600;

            $this->release($delay);
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function isTransient(array $response): bool
    {
        $httpStatus = (int) ($response['http_status'] ?? 0);

        if (in_array($httpStatus, [0, 408, 429], true) || $httpStatus >= 500) {
            return true;
        }

        if (($response['error_code'] ?? 0) >= 900) {
            return true;
        }

        // A response that is neither a success nor a fault is unexpected, treat it as transient.
        return !($response['fault'] ?? false);
    }

    private function systemLogType(MontenegroFiscalLog $log): int
    {
        if ($log->isCorrective()) {
            return SystemLog::TYPE_MONTENEGRO_CANCELLATION;
        }

        if ($log->kind === MontenegroFiscalLog::KIND_ADVANCE) {
            return SystemLog::TYPE_MONTENEGRO_ADVANCE;
        }

        if ($log->subseq_deliv_type !== null) {
            return SystemLog::TYPE_MONTENEGRO_SUBSEQUENT;
        }

        return SystemLog::TYPE_MONTENEGRO_INVOICE;
    }

    private function writeActivity(Invoice $invoice, int $activity_id, string $notes = ''): void
    {
        $activity = new Activity();
        $activity->user_id = $invoice->user_id;
        $activity->client_id = $invoice->client_id;
        $activity->company_id = $invoice->company_id;
        $activity->account_id = $invoice->company->account_id;
        $activity->activity_type_id = $activity_id;
        $activity->invoice_id = $this->entity_type === 'credit' ? null : $invoice->id;
        $activity->credit_id = $this->entity_type === 'credit' ? $invoice->id : null;
        $activity->notes = str_replace('"', '', $notes);
        $activity->is_system = true;

        $activity->save();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function systemLog(Invoice $invoice, array $data, int $event_id, int $type_id): void
    {
        (new SystemLogger(
            $data,
            SystemLog::CATEGORY_MONTENEGRO,
            $event_id,
            $type_id,
            $invoice->client,
            $invoice->company
        ))->handle();
    }

    public function failed(?\Throwable $exception = null): void
    {
        if ($exception !== null) {
            nlog('MONTENEGRO FISCALIZATION JOB FAILED: ' . $exception->getMessage());
        }

        if ($this->log_id === null) {
            return;
        }

        $log = MontenegroFiscalLog::find($this->log_id);

        if ($log === null || $log->isSent()) {
            return;
        }

        $log->fill([
            'status' => MontenegroFiscalLog::STATUS_FAILED,
            'error_message' => $exception?->getMessage(),
            'subseq_deliv_type' => $this->subseq ??= SubseqDelivType::SERVICE->value,
        ])->save();

        $invoice = $this->entity_type === 'credit'
            ? Credit::withTrashed()->find($this->invoice_id)
            : Invoice::withTrashed()->find($this->invoice_id);

        if ($invoice && $invoice->backup->e_invoice_status !== 'sent') {
            $invoice->backup->e_invoice_status = 'failed';
            $invoice->saveQuietly();
        }
    }
}
