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

use App\Services\EDocument\Standards\Montenegro\Enums\SubseqDelivType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ExportRequest
 *
 * Validates an offline export of signed fiscalization messages for invoices
 * that could not be delivered to the CIS.
 */
class ExportRequest extends FormRequest
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
            'invoice_ids' => ['sometimes', 'nullable', 'string'],
            'subsequent_delivery' => ['sometimes', 'nullable', Rule::in(array_column(SubseqDelivType::cases(), 'value'))],
        ];
    }
}
