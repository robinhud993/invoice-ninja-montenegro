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

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * MontenegroFiscalCounter
 *
 * Per company, per year monotonic sequence for the EFI InvOrdNum (invoice
 * ordinal number). The ordinal resets on the first of January.
 *
 * @property int $id
 * @property int $company_id
 * @property int $year
 * @property int $last_ordinal
 */
class MontenegroFiscalCounter extends Model
{
    public $timestamps = true;

    protected $table = 'montenegro_fiscal_counters';

    protected $guarded = ['id'];

    protected $casts = [
        'year' => 'integer',
        'last_ordinal' => 'integer',
    ];

    /**
     * Atomically reserves and returns the next ordinal for the company and year.
     */
    public static function nextOrdinal(int $companyId, int $year): int
    {
        return DB::transaction(function () use ($companyId, $year) {
            // Insert the counter row if it does not exist yet. insertOrIgnore is
            // atomic, so concurrent first use cannot raise a unique violation.
            DB::table('montenegro_fiscal_counters')->insertOrIgnore([
                'company_id' => $companyId,
                'year' => $year,
                'last_ordinal' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = static::query()
                ->where('company_id', $companyId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $counter->last_ordinal++;
            $counter->save();

            return $counter->last_ordinal;
        });
    }

    /**
     * Returns the next ordinal without consuming it. Used when previewing a
     * document, for example when rendering a QR code or a draft.
     */
    public static function peekOrdinal(int $companyId, int $year): int
    {
        $counter = static::query()
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->first();

        return ($counter?->last_ordinal ?? 0) + 1;
    }
}
