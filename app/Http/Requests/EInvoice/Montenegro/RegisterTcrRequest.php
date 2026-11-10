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

use App\Services\EDocument\Standards\Montenegro\Enums\TcrType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RegisterTcrRequest
 *
 * Validates a registerTCR submission: registration of an electronic fiscal
 * device (ENU) with the CIS.
 */
class RegisterTcrRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return (bool) $user?->isAdmin();
    }

    public function rules(): array
    {
        $registrationCode = ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}$/'];

        return [
            'business_unit_code' => $registrationCode,
            'tcr_internal_id' => ['required', 'string', 'max:50'],
            'software_code' => $registrationCode,
            'maintainer_code' => $registrationCode,
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'type' => ['sometimes', 'nullable', Rule::in(array_column(TcrType::cases(), 'value'))],
        ];
    }

    public function messages(): array
    {
        return [
            'business_unit_code.regex' => 'Registration codes use the format ab123ab123.',
            'software_code.regex' => 'Registration codes use the format ab123ab123.',
            'maintainer_code.regex' => 'Registration codes use the format ab123ab123.',
        ];
    }
}
