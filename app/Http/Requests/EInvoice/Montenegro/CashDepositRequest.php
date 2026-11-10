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

use App\Services\EDocument\Standards\Montenegro\Enums\CashDepositOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CashDepositRequest
 *
 * Validates a registerCashDeposit submission: the daily cash register deposit
 * or withdrawal report.
 */
class CashDepositRequest extends FormRequest
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
            'operation' => ['required', Rule::in(array_column(CashDepositOperation::cases(), 'value'))],
            'cash_amount' => ['required', 'numeric', 'min:0'],
            'tcr_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'change_datetime' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
