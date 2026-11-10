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

class StatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        return (bool) $user?->isAdmin();
    }

    public function rules(): array
    {
        return [];
    }
}
