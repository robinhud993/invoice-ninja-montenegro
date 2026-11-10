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

use RuntimeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MontenegroFiscalLog
 *
 * One row per fiscalization attempt against the EFI Central Information System.
 *
 * @property int $id
 * @property int $company_id
 * @property int $invoice_id
 * @property int|null $client_id
 * @property string $kind
 * @property string $status
 * @property string|null $iic
 * @property string|null $jikr
 * @property string|null $document_type
 * @property string|null $subseq_deliv_type
 * @property string|null $request_uuid
 * @property string|null $response_uuid
 * @property int $attempts
 * @property int|null $error_code
 * @property string|null $error_message
 * @property float $total
 * @property array|null $request_state
 * @property object|null $response
 * @property \Carbon\Carbon|null $sent_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\Invoice $invoice
 * @property-read \App\Models\Client|null $client
 */
class MontenegroFiscalLog extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_VOID = 'void';

    /**
     * Document kinds, mirrors Enums\DocumentKind.
     */
    public const KIND_CREATE = 'create';
    public const KIND_ADVANCE = 'advance';
    public const KIND_CORRECTIVE = 'corrective';
    public const KIND_CREDIT = 'credit';
    public const KIND_DEBIT = 'debit';
    public const KIND_BADDEBT = 'baddebt';

    public $timestamps = true;

    protected $table = 'montenegro_fiscal_logs';

    protected $casts = [
        'request_state' => 'array',
        'response' => 'object',
        'sent_at' => 'datetime',
        'total' => 'float',
        'error_code' => 'integer',
        'attempts' => 'integer',
    ];

    protected $guarded = ['id'];

    /**
     * Fiscal records are legal documents. Records younger than the retention
     * window may not be deleted through the model. The retention command
     * deliberately purges through the query builder once the window elapses.
     */
    protected static function booted(): void
    {
        static::deleting(function (MontenegroFiscalLog $log): void {
            if ($log->withinRetention()) {
                throw new RuntimeException(
                    'Montenegro fiscal records must be retained for ' . self::retentionDays() . ' days and cannot be deleted.'
                );
            }
        });
    }

    /**
     * Number of days a fiscal record is retained. Zero disables purging
     * entirely, which keeps every record forever.
     */
    public static function retentionDays(): int
    {
        return max(0, (int) config('services.montenegro.fiscal_log_retention_days', 3650));
    }

    public function scopeFiscalized(Builder $query): Builder
    {
        return $query->whereNotNull('jikr');
    }

    public function scopeUnfiscalized(Builder $query): Builder
    {
        return $query->whereNull('jikr');
    }

    public function isFiscalized(): bool
    {
        return $this->jikr !== null && $this->jikr !== '';
    }

    /**
     * True while the record is still inside the legal retention window and must
     * therefore not be deleted.
     */
    public function withinRetention(): bool
    {
        $days = self::retentionDays();

        if ($days === 0) {
            return true;
        }

        if ($this->created_at === null) {
            return true;
        }

        return $this->created_at->gt(now()->subDays($days));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(Credit::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true);
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCorrective(): bool
    {
        return in_array($this->kind, [self::KIND_CORRECTIVE, self::KIND_CREDIT, self::KIND_DEBIT, self::KIND_BADDEBT], true);
    }
}
