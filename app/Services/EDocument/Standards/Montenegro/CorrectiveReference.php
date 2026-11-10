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

use App\Services\EDocument\Standards\Montenegro\Enums\CorrectiveType;

/**
 * CorrectiveReference
 *
 * The original invoice data required by the me:CorrectiveInv and me:BadDebtInv
 * elements. A storno always references the original IKOF (IIC) and the original
 * issue date and time.
 */
class CorrectiveReference
{
    public function __construct(
        public readonly string $iic,
        public readonly string $issueDateTime,
        public readonly ?CorrectiveType $type = CorrectiveType::CORRECTIVE,
    ) {}
}
