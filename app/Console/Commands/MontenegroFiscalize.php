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

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Utils\Traits\MakesHash;
use Illuminate\Console\Command;
use App\Services\EDocument\Standards\Montenegro\Enums\DocumentKind;
use App\Services\EDocument\Standards\Montenegro\Jobs\SendToCis;

/**
 * Queues Montenegro EFI fiscalization for an invoice, or for every issued
 * invoice of a company that does not have a JIKR yet.
 *
 * Useful when montenegro_auto_fiscalize is disabled, so issuing an invoice does
 * not fiscalize it automatically.
 */
class MontenegroFiscalize extends Command
{
    use MakesHash;

    protected $signature = 'ninja:montenegro-fiscalize
        {invoice? : The hashed invoice id or the invoice number}
        {--company= : The hashed company id, required together with --all}
        {--all : Fiscalize every issued invoice of the company that has no JIKR yet}';

    protected $description = 'Queues Montenegro EFI fiscalization for an invoice';

    public function handle(): int
    {
        $company = null;

        if ($this->option('company')) {
            $company = Company::query()->find($this->decodePrimaryKey($this->option('company')));

            if (!$company) {
                $this->error('Company not found for the supplied hashed id.');

                return self::FAILURE;
            }
        }

        if ($this->option('all')) {
            if (!$company) {
                $this->error('--company is required when using --all.');

                return self::FAILURE;
            }

            if (!$company->montenegroEnabled()) {
                $this->error('The company is not using the Montenegro standard.');

                return self::FAILURE;
            }

            return $this->fiscalizeCompany($company);
        }

        $reference = $this->argument('invoice');

        if (!$reference) {
            $this->error('Provide an invoice id/number, or use --all with --company.');

            return self::FAILURE;
        }

        $invoice = $this->resolveInvoice($reference);

        if (!$invoice) {
            $this->error("Invoice not found: {$reference}");

            return self::FAILURE;
        }

        if (!$invoice->company->montenegroEnabled()) {
            $this->error('The invoice company is not using the Montenegro standard.');

            return self::FAILURE;
        }

        $invoice->service()->sendMontenegro();

        $this->info("Queued Montenegro fiscalization for invoice {$invoice->number}.");

        return self::SUCCESS;
    }

    private function fiscalizeCompany(Company $company): int
    {
        $queued = 0;
        $skipped = 0;

        $company->invoices()
            ->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL, Invoice::STATUS_PAID])
            ->where('is_deleted', false)
            ->orderBy('id')
            ->cursor()
            ->each(function (Invoice $invoice) use ($company, &$queued, &$skipped): void {
                if (strlen((string) ($invoice->backup->guid ?? '')) > 0) {
                    $skipped++;

                    return;
                }

                SendToCis::dispatch($invoice->id, $company, DocumentKind::CREATE->value);
                $queued++;
            });

        $this->info("Queued {$queued} invoice(s) for fiscalization, skipped {$skipped} already fiscalized.");

        return self::SUCCESS;
    }

    private function resolveInvoice(string $reference): ?Invoice
    {
        $decoded = $this->decodePrimaryKey($reference, true);

        if ($decoded && $decoded !== 'Invalid Primary Key') {
            $invoice = Invoice::query()->find($decoded);

            if ($invoice) {
                return $invoice;
            }
        }

        return Invoice::query()->where('number', $reference)->orderBy('id', 'desc')->first();
    }
}
