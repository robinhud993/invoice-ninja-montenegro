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

namespace App\Http\Requests\EInvoice\Montenegro;

use App\Models\MontenegroFiscalLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FiscalReportRequest
 *
 * Validates a fiscalization reporting query. The optional group_by produces a
 * day or month timeline in addition to the flat summary.
 */
class FiscalReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return (bool) $user?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'group_by' => ['sometimes', 'nullable', Rule::in(['day', 'month'])],
            'status' => ['sometimes', 'nullable', Rule::in([
                MontenegroFiscalLog::STATUS_PENDING,
                MontenegroFiscalLog::STATUS_SENT,
                MontenegroFiscalLog::STATUS_FAILED,
                MontenegroFiscalLog::STATUS_REJECTED,
                MontenegroFiscalLog::STATUS_VOID,
            ])],
            'kind' => ['sometimes', 'nullable', Rule::in([
                MontenegroFiscalLog::KIND_CREATE,
                MontenegroFiscalLog::KIND_ADVANCE,
                MontenegroFiscalLog::KIND_CORRECTIVE,
                MontenegroFiscalLog::KIND_CREDIT,
                MontenegroFiscalLog::KIND_DEBIT,
                MontenegroFiscalLog::KIND_BADDEBT,
            ])],
        ];
    }
}
