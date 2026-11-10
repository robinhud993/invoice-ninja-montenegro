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

use Carbon\Carbon;
use App\Models\Company;
use App\Utils\Traits\MakesHash;
use Illuminate\Console\Command;
use App\Services\EDocument\Standards\Montenegro\MontenegroFiscalLogExporter;

/**
 * Exports the Montenegro EFI fiscal journal for tax inspection, as CSV or JSON,
 * for a company and optional date range.
 */
class MontenegroExportLogs extends Command
{
    use MakesHash;

    protected $signature = 'ninja:montenegro-export-logs
        {--company= : The hashed company id}
        {--from= : Include records created on or after this date (Y-m-d)}
        {--to= : Include records created on or before this date (Y-m-d)}
        {--status= : Filter by status}
        {--kind= : Filter by document kind}
        {--format=csv : csv or json}
        {--output= : Optional destination path}';

    protected $description = 'Exports the Montenegro EFI fiscal journal for a company';

    public function handle(): int
    {
        $company = Company::query()->find($this->decodePrimaryKey($this->option('company')));

        if (! $company) {
            $this->error('Company not found for the supplied hashed id.');

            return self::FAILURE;
        }

        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['csv', 'json'], true)) {
            $this->error('The format must be csv or json.');

            return self::FAILURE;
        }

        try {
            $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : null;
            $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : null;
        } catch (\Throwable $e) {
            $this->error('The from/to dates could not be parsed.');

            return self::FAILURE;
        }

        $exporter = new MontenegroFiscalLogExporter(
            company: $company,
            from: $from,
            to: $to,
            status: $this->option('status') ?: null,
            kind: $this->option('kind') ?: null,
        );

        $output = $this->option('output') ?: storage_path('app/' . $exporter->filename($format));

        if ($format === 'json') {
            $records = $exporter->toArray((int) config('services.montenegro.fiscal_log_export_limit', 50000));
            file_put_contents($output, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Exported ' . count($records) . " fiscal record(s) to {$output}");

            return self::SUCCESS;
        }

        $stream = fopen($output, 'w');

        if ($stream === false) {
            $this->error("Unable to open {$output} for writing.");

            return self::FAILURE;
        }

        $count = $exporter->writeCsv($stream);
        fclose($stream);

        $this->info("Exported {$count} fiscal record(s) to {$output}");

        return self::SUCCESS;
    }
}
