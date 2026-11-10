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

namespace App\Jobs\Cron;

use App\Models\Company;
use App\Libraries\MultiDB;
use App\Models\MontenegroFiscalLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use App\Services\EDocument\Standards\Montenegro\Jobs\SendToCis;
use App\Services\EDocument\Standards\Montenegro\Enums\SubseqDelivType;
use Throwable;

/**
 * MontenegroFiscalizationCron
 *
 * Implements the subsequent fiscalization requirement: invoices that were
 * issued without a JIKR (offline ENU, fiscalization service outage, or a local
 * technical error) are retried within the legally allowed window and marked with
 * the appropriate SubseqDelivType.
 *
 * Article 17 of the Fiscalisation Law allows 2 days for a temporary internet
 * outage and 5 days for a fiscalization service failure.
 */
class MontenegroFiscalizationCron implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public $timeout = 1200;

    public function __construct(
        private ?int $companyId = null,
        private ?string $db = null,
    ) {}

    public function middleware(): array
    {
        $key = $this->companyId && $this->db
            ? 'montenegro-fiscalization-company-' . sha1($this->db . '|' . $this->companyId)
            : 'montenegro-fiscalization-cron-dispatch';

        return [
            (new WithoutOverlapping($key))
                ->releaseAfter(60)
                ->expireAfter(3600),
        ];
    }

    public function handle(): void
    {
        if ($this->companyId && $this->db) {
            MultiDB::setDB($this->db);

            $company = Company::query()
                ->whereKey($this->companyId)
                ->where('is_disabled', false)
                ->whereHas('account', fn($query) => $query->where('is_flagged', false))
                ->first();

            if ($company && $company->montenegroEnabled()) {
                $this->processCompany($company);
            }

            return;
        }

        if (config('ninja.db.multi_db_enabled')) {
            foreach (MultiDB::$dbs as $db) {
                try {
                    MultiDB::setDB($db);
                    $this->dispatchCompanies($db);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return;
        }

        $this->dispatchCompanies((string) config('database.default'));
    }

    private function dispatchCompanies(string $db): void
    {
        Company::query()
            ->where('is_disabled', false)
            ->whereHas('account', fn($query) => $query->where('is_flagged', false))
            ->orderBy('id')
            ->cursor()
            ->filter(fn(Company $company): bool => $company->montenegroEnabled())
            ->each(fn(Company $company) => self::dispatch($company->id, $company->db ?: $db));
    }

    private function processCompany(Company $company): void
    {
        // Query with the widest window, then enforce each log's own window: the
        // law allows 2 days for a temporary internet outage and 5 days for a
        // fiscalization service failure.
        $widestWindow = max(array_map(
            fn (SubseqDelivType $type): int => $type->retryWindowDays(),
            SubseqDelivType::cases()
        ));

        MontenegroFiscalLog::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [MontenegroFiscalLog::STATUS_PENDING, MontenegroFiscalLog::STATUS_FAILED])
            ->whereNull('jikr')
            ->where('created_at', '>=', now()->subDays($widestWindow))
            ->orderBy('id')
            ->eachById(function (MontenegroFiscalLog $log) use ($company): void {
                $subseq = SubseqDelivType::fromSetting($log->subseq_deliv_type) ?? SubseqDelivType::SERVICE;

                if ($log->created_at->lt(now()->subDays($subseq->retryWindowDays()))) {
                    return;
                }

                $entity_id = $log->credit_id ?: $log->invoice_id;
                $entity_type = $log->credit_id ? 'credit' : 'invoice';

                SendToCis::dispatch(
                    $entity_id,
                    $company,
                    $log->kind,
                    $log->isCorrective() ? [
                        'iic' => (string) ($log->request_state['corrective']['iic'] ?? ''),
                        'issue_date_time' => (string) ($log->request_state['corrective']['issue_date_time'] ?? ''),
                        'type' => (string) ($log->document_type ?? 'CREDIT'),
                    ] : null,
                    $subseq->value,
                    null,
                    $log->id,
                    $entity_type,
                );
            });
    }
}
