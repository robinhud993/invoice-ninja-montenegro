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

use Illuminate\Foundation\Http\FormRequest;

/**
 * SaveEnuRequest
 *
 * Validates the registration of an electronic fiscal device (ENU / TCR) in the
 * company ENU registry.
 */
class SaveEnuRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return (bool) $user?->isAdmin();
    }

    public function rules(): array
    {
        $registrationCode = ['required', 'string', 'regex:/^[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}$/'];
        $optionalCode = ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}$/'];

        return [
            'id' => ['sometimes', 'nullable', 'string'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'device_type' => ['sometimes', 'nullable', 'string', 'in:REGULAR,VENDING'],
            'sales_type' => ['sometimes', 'nullable', 'string', 'max:30'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:30'],
            'tcr_internal_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tcr_code' => $registrationCode,
            'business_unit_code' => $registrationCode,
            'operator_code' => $registrationCode,
            'software_code' => $optionalCode,
            'maintainer_code' => $optionalCode,
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tcr_code.regex' => 'Registration codes use the format ab123ab123.',
            'business_unit_code.regex' => 'Registration codes use the format ab123ab123.',
            'operator_code.regex' => 'Registration codes use the format ab123ab123.',
            'software_code.regex' => 'Registration codes use the format ab123ab123.',
            'maintainer_code.regex' => 'Registration codes use the format ab123ab123.',
        ];
    }
}
