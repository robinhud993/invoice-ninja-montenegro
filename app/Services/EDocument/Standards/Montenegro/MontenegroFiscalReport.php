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
use Illuminate\Support\Facades\DB;
use App\Services\EDocument\Standards\Montenegro\Enums\SubseqDelivType;

/**
 * MontenegroFiscalReport
 *
 * Aggregates the Montenegro EFI fiscal journal into a reporting payload:
 * fiscalization success rates, amounts, breakdowns by document kind, status and
 * subsequent delivery type, the open backlog with its legal retry window, and an
 * optional day/month timeline.
 *
 * "Fiscalized" means the CIS returned a JIKR. Rows without a JIKR are open
 * either for automatic resend or manual escalation.
 */
class MontenegroFiscalReport
{
    public function __construct(
        private Company $company,
        private ?CarbonInterface $from = null,
        private ?CarbonInterface $to = null,
        private ?string $groupBy = null,
        private ?string $status = null,
        private ?string $kind = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $totals = $this->totals();
        $maxWindow = $this->maxRetryWindowDays();

        return [
            'generated_at' => now()->toIso8601String(),
            'date_from' => $this->from?->toIso8601String(),
            'date_to' => $this->to?->toIso8601String(),
            'group_by' => $this->groupBy,
            'currency_id' => (string) ($this->company->settings->currency_id ?? ''),
            'retry_window_days' => $maxWindow,
            'totals' => $totals,
            'by_kind' => $this->breakdown('kind'),
            'by_status' => $this->breakdown('status'),
            'by_subseq_deliv_type' => $this->breakdown('subseq_deliv_type'),
            'backlog' => $this->backlog($maxWindow),
            'timeline' => $this->timeline(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(): array
    {
        $row = $this->query()
            ->selectRaw('COUNT(*) as records')
            ->selectRaw('SUM(CASE WHEN jikr IS NOT NULL THEN 1 ELSE 0 END) as fiscalized')
            ->selectRaw('SUM(CASE WHEN jikr IS NULL THEN 1 ELSE 0 END) as unfiscalized')
            ->selectRaw('COALESCE(SUM(total), 0) as amount_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN jikr IS NOT NULL THEN total ELSE 0 END), 0) as amount_fiscalized')
            ->selectRaw('COALESCE(SUM(CASE WHEN jikr IS NULL THEN total ELSE 0 END), 0) as amount_unfiscalized')
            ->first();

        $records = (int) ($row->records ?? 0);
        $fiscalized = (int) ($row->fiscalized ?? 0);

        return [
            'records' => $records,
            'fiscalized' => $fiscalized,
            'unfiscalized' => (int) ($row->unfiscalized ?? 0),
            'success_rate' => $records > 0 ? round($fiscalized / $records, 4) : null,
            'amount_total' => round((float) ($row->amount_total ?? 0), 2),
            'amount_fiscalized' => round((float) ($row->amount_fiscalized ?? 0), 2),
            'amount_unfiscalized' => round((float) ($row->amount_unfiscalized ?? 0), 2),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breakdown(string $column): array
    {
        return $this->query()
            ->select($column)
            ->selectRaw('COUNT(*) as records')
            ->selectRaw('SUM(CASE WHEN jikr IS NOT NULL THEN 1 ELSE 0 END) as fiscalized')
            ->selectRaw('COALESCE(SUM(total), 0) as amount_total')
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn ($row): array => [
                $column => $row->{$column},
                'records' => (int) $row->records,
                'fiscalized' => (int) $row->fiscalized,
                'unfiscalized' => (int) $row->records - (int) $row->fiscalized,
                'amount_total' => round((float) $row->amount_total, 2),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function backlog(int $maxWindow): array
    {
        $open = fn (): Builder => $this->query()
            ->whereNull('jikr')
            ->whereIn('status', [MontenegroFiscalLog::STATUS_PENDING, MontenegroFiscalLog::STATUS_FAILED]);

        $cutoff = now()->subDays($maxWindow);
        $oldest = (clone $open())->min('created_at');

        return [
            'open' => (clone $open())->count(),
            'within_window' => (clone $open())->where('created_at', '>=', $cutoff)->count(),
            'outside_window' => (clone $open())->where('created_at', '<', $cutoff)->count(),
            'oldest_created_at' => $oldest ? (string) $oldest : null,
            'oldest_age_days' => $oldest ? (int) abs(now()->diffInDays($oldest)) : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timeline(): array
    {
        if ($this->groupBy === null) {
            return [];
        }

        $period = $this->periodExpression($this->groupBy);

        return $this->query()
            ->selectRaw("{$period} as period")
            ->selectRaw('COUNT(*) as records')
            ->selectRaw('SUM(CASE WHEN jikr IS NOT NULL THEN 1 ELSE 0 END) as fiscalized')
            ->selectRaw('COALESCE(SUM(total), 0) as amount_total')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row): array => [
                'period' => (string) $row->period,
                'records' => (int) $row->records,
                'fiscalized' => (int) $row->fiscalized,
                'unfiscalized' => (int) $row->records - (int) $row->fiscalized,
                'amount_total' => round((float) $row->amount_total, 2),
            ])
            ->all();
    }

    private function query(): Builder
    {
        return MontenegroFiscalLog::query()
            ->where('company_id', $this->company->id)
            ->when($this->from, fn (Builder $query): Builder => $query->where('created_at', '>=', $this->from))
            ->when($this->to, fn (Builder $query): Builder => $query->where('created_at', '<=', $this->to))
            ->when($this->status, fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->kind, fn (Builder $query): Builder => $query->where('kind', $this->kind));
    }

    private function maxRetryWindowDays(): int
    {
        return max(array_map(
            fn (SubseqDelivType $type): int => $type->retryWindowDays(),
            SubseqDelivType::cases()
        ));
    }

    /**
     * Database aware period bucket expression, so the report works on MySQL,
     * PostgreSQL and SQLite.
     */
    private function periodExpression(string $groupBy): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => $groupBy === 'month'
                ? "to_char(created_at, 'YYYY-MM')"
                : "to_char(created_at, 'YYYY-MM-DD')",
            'sqlite' => $groupBy === 'month'
                ? "strftime('%Y-%m', created_at)"
                : "strftime('%Y-%m-%d', created_at)",
            default => $groupBy === 'month'
                ? "DATE_FORMAT(created_at, '%Y-%m')"
                : 'DATE(created_at)',
        };
    }
}
