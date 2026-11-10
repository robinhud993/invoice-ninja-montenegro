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

namespace App\Services\EDocument\Standards\Montenegro;

use App\Services\EDocument\Standards\Montenegro\Enums\FeeType;

/**
 * FeeTotals
 *
 * A single EFI fee (me:FeeType), reported separately from the invoice items.
 */
class FeeTotals
{
    public function __construct(
        public readonly FeeType $type,
        public readonly float $amount,
    ) {}
}
