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
use App\Services\EDocument\Standards\Montenegro\MontenegroExporter;

/**
 * Exports signed Montenegro EFI messages for invoices that have not been
 * fiscalized, so they can be delivered manually to the Tax Administration.
 */
class MontenegroExport extends Command
{
    use MakesHash;

    protected $signature = 'ninja:montenegro-export
        {--company= : The hashed company id}
        {--output= : Optional destination path for the zip archive}';

    protected $description = 'Exports signed Montenegro EFI messages for unfiscalized invoices';

    public function handle(): int
    {
        $company = Company::query()->find($this->decodePrimaryKey($this->option('company')));

        if (!$company) {
            $this->error('Company not found for the supplied hashed id.');

            return self::FAILURE;
        }

        if (!$company->montenegroEnabled()) {
            $this->error('The company is not using the Montenegro standard.');

            return self::FAILURE;
        }

        $invoices = Invoice::query()
            ->where('company_id', $company->id)
            ->whereIn('status_id', [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL, Invoice::STATUS_PAID])
            ->where('is_deleted', false)
            ->whereDoesntHave('montenegro_fiscal_logs', fn ($query) => $query->whereNotNull('jikr'))
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($invoices->isEmpty()) {
            $this->info('No unfiscalized invoices were found to export.');

            return self::SUCCESS;
        }

        $result = (new MontenegroExporter($company))->export($invoices);

        if ($output = $this->option('output')) {
            if (!@rename($result['path'], $output)) {
                $this->error("Unable to move the export to {$output}");

                return self::FAILURE;
            }

            $result['path'] = $output;
        }

        $this->info("Exported {$result['exported']} invoice(s) to {$result['path']}");

        foreach ($result['failed'] as $failure) {
            $this->warn($failure);
        }

        return self::SUCCESS;
    }
}
