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
 * MontenegroEnu
 *
 * A registered electronic fiscal device (ENU / TCR). A taxpayer may operate
 * several devices, each with its own TCR code, business unit code and operator
 * code, so the invoice may select which ENU issued it.
 *
 * @property int $id
 * @property int $company_id
 * @property string|null $name
 * @property string|null $device_type
 * @property string|null $sales_type
 * @property string|null $payment_method
 * @property string|null $tcr_internal_id
 * @property string $tcr_code
 * @property string $business_unit_code
 * @property string $operator_code
 * @property string|null $software_code
 * @property string|null $maintainer_code
 * @property string|null $valid_from
 * @property string|null $valid_to
 * @property bool $is_default
 * @property bool $is_active
 * @property-read \App\Models\Company $company
 */
class MontenegroEnu extends Model
{
    protected $table = 'montenegro_enus';

    protected $guarded = ['id'];

    protected $casts = [
        'is_default' => 'bool',
        'is_active' => 'bool',
    ];

    protected $hidden = ['company'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The ENU used when an invoice does not select one explicitly.
     */
    public static function defaultFor(Company $company): ?self
    {
        return static::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
