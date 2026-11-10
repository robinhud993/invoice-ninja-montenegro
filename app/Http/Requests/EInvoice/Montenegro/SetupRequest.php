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

use App\Services\EDocument\Standards\Montenegro\FiscalSettings;
use App\Services\EDocument\Standards\Montenegro\Enums\InvoiceType;
use App\Services\EDocument\Standards\Montenegro\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SetupRequest
 *
 * Validates the organization level Montenegro EFI setup: signing certificate,
 * certificate passphrase, environment selection and the CIS assigned
 * registration codes.
 */
class SetupRequest extends FormRequest
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
            'enable' => 'sometimes|boolean',
            'remove_certificate' => 'sometimes|boolean',

            'certificate' => 'sometimes|nullable|file|mimes:p12,pfx,pem,cer,crt,der,txt,p7b,spc,bin|max:5120',
            'certificate_passphrase' => 'sometimes|nullable|string|max:255',
            'test_mode' => 'sometimes|boolean',

            'tin' => ['sometimes', 'nullable', 'string', 'regex:/^([0-9]{8}|[0-9]{13})$/'],
            'tcr_code' => $registrationCode,
            'business_unit_code' => $registrationCode,
            'operator_code' => $registrationCode,
            'software_code' => $registrationCode,
            'maintainer_code' => ['sometimes', 'nullable', 'string', 'max:10'],

            'is_in_vat' => 'sometimes|boolean',
            'invoice_type' => ['sometimes', 'nullable', Rule::in(array_column(InvoiceType::cases(), 'value'))],
            'payment_method' => ['sometimes', 'nullable', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'advance_marker' => 'sometimes|nullable|string|max:50',
            'bank_account' => 'sometimes|nullable|string|max:34',
            'comp_card' => 'sometimes|nullable|string|max:50',
            'self_iss_type' => ['sometimes', 'nullable', Rule::in(FiscalSettings::SELF_ISS_TYPES)],
            'reverse_charge' => 'sometimes|boolean',
            'auto_fiscalize' => 'sometimes|boolean',
            'require_buyer_tin' => 'sometimes|boolean',
            'simplified_invoice' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'tin.regex' => 'The TIN must be the 8 digit PIB or the 13 digit JMB.',
            'tcr_code.regex' => 'Registration codes use the format ab123ab123 (two lowercase letters, three digits, two lowercase letters, three digits).',
            'business_unit_code.regex' => 'Registration codes use the format ab123ab123 (two lowercase letters, three digits, two lowercase letters, three digits).',
            'operator_code.regex' => 'Registration codes use the format ab123ab123 (two lowercase letters, three digits, two lowercase letters, three digits).',
            'software_code.regex' => 'Registration codes use the format ab123ab123 (two lowercase letters, three digits, two lowercase letters, three digits).',
        ];
    }
}
