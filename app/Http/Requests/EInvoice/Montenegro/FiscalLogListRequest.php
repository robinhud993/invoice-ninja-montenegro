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
 * FiscalLogListRequest
 *
 * Validates a filtered, paginated read of the Montenegro fiscal journal.
 */
class FiscalLogListRequest extends FormRequest
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
            'invoice_id' => ['sometimes', 'nullable', 'string'],
            'credit_id' => ['sometimes', 'nullable', 'string'],
            'order' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
