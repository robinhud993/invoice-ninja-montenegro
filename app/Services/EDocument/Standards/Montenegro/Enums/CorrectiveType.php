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

namespace App\Services\EDocument\Standards\Montenegro\Enums;

/**
 * EFI CorrectiveInvTypeSType.
 *
 * Technical specification v5 section 3.7.1.30: DEBIT and CREDIT were removed on
 * 1.10.2021. A corrective invoice fixes an original that remains valid; an
 * error corrective is a full storno of an original that was reported in error.
 */
enum CorrectiveType: string
{
    case CORRECTIVE = 'CORRECTIVE';
    case ERROR_CORRECTIVE = 'ERROR_CORRECTIVE';

    public function label(): string
    {
        return match ($this) {
            self::CORRECTIVE => 'Corrective invoice',
            self::ERROR_CORRECTIVE => 'Error corrective (full storno)',
        };
    }
}
