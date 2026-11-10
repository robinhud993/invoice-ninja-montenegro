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

use Throwable;
use App\Models\Company;
use App\Libraries\MultiDB;
use App\Models\MontenegroFiscalLog;
use App\Utils\Traits\MakesHash;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Purges Montenegro EFI fiscal records only after the legal retention window has
 * elapsed. Recent records are protected by MontenegroFiscalLog::deleting, so the
 * purge deliberately goes through the query builder once the window has passed.
 */
class MontenegroLogRetention extends Command
{
    use MakesHash;

    protected $signature = 'ninja:montenegro-log-retention
        {--company= : The hashed company id}
        {--days= : Override the configured retention window in days}
        {--dry-run : Report the records that would be purged without deleting them}';

    protected $description = 'Purges Montenegro EFI fiscal records older than the legal retention window';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? max(0, (int) $this->option('days'))
            : MontenegroFiscalLog::retentionDays();

        if ($days === 0) {
            $this->info('Retention is disabled: no Montenegro fiscal records will be purged.');

            return self::SUCCESS;
        }

        $companyId = null;

        if ($this->option('company')) {
            $companyId = (int) $this->decodePrimaryKey($this->option('company'));

            if (! Company::query()->whereKey($companyId)->exists()) {
                $this->error('Company not found for the supplied hashed id.');

                return self::FAILURE;
            }
        }

        if (config('ninja.db.multi_db_enabled')) {
            foreach (MultiDB::$dbs as $db) {
                try {
                    MultiDB::setDB($db);
                    $this->purge($days, $companyId);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return self::SUCCESS;
        }

        $this->purge($days, $companyId);

        return self::SUCCESS;
    }

    private function purge(int $days, ?int $companyId): void
    {
        $cutoff = now()->subDays($days);

        $query = MontenegroFiscalLog::query()
            ->where('created_at', '<', $cutoff)
            ->when($companyId, fn (Builder $builder): Builder => $builder->where('company_id', $companyId));

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'Would purge %d Montenegro fiscal record(s) created before %s.',
                (clone $query)->count(),
                $cutoff->toDateString(),
            ));

            return;
        }

        $purged = 0;

        do {
            $chunk = (clone $query)->limit(1000)->delete();
            $purged += $chunk;
        } while ($chunk > 0);

        $this->info(sprintf(
            'Purged %d Montenegro fiscal record(s) created before %s.',
            $purged,
            $cutoff->toDateString(),
        ));
    }
}
