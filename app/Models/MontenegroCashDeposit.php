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
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MontenegroCashDeposit
 *
 * Audit record for a registerCashDeposit report sent to the EFI CIS.
 *
 * @property int $id
 * @property int $company_id
 * @property string $tcr_code
 * @property string $operation
 * @property float $cash_amt
 * @property \Carbon\Carbon|null $change_datetime
 * @property string|null $fcdc
 * @property string $status
 * @property int|null $error_code
 * @property string|null $error_message
 * @property object|null $response
 * @property \Carbon\Carbon|null $sent_at
 * @property-read \App\Models\Company $company
 */
class MontenegroCashDeposit extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $table = 'montenegro_cash_deposits';

    protected $guarded = ['id'];

    protected $casts = [
        'response' => 'object',
        'sent_at' => 'datetime',
        'change_datetime' => 'datetime',
        'cash_amt' => 'float',
        'error_code' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /**
     * Specification 3.6: an INITIAL deposit must be registered each day before
     * the first cash invoice is issued from a device. Returns true when a
     * successful INITIAL deposit exists for today.
     */
    public static function hasInitialForToday(int $companyId, string $tcrCode): bool
    {
        $today = now()->toDateString();

        return static::query()
            ->where('company_id', $companyId)
            ->where('tcr_code', $tcrCode)
            ->where('operation', 'INITIAL')
            ->where('status', self::STATUS_SENT)
            ->where(function ($query) use ($today) {
                $query->whereDate('change_datetime', $today)
                    ->orWhereDate('created_at', $today);
            })
            ->exists();
    }
}
