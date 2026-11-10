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
 * Internal classification of the fiscalization operation.
 *
 * This drives which optional EFI elements are emitted:
 *  - CREATE     -> InvType INVOICE
 *  - ADVANCE    -> InvType ADVANCE
 *  - CORRECTIVE -> InvType CORRECTIVE with a CorrectiveInv reference
 *  - CREDIT     -> InvType CORRECTIVE with a CorrectiveInv reference
 *  - DEBIT      -> InvType CORRECTIVE with a CorrectiveInv reference
 *  - BADDEBT    -> InvType INVOICE with a BadDebtInv reference
 *
 * Technical specification v5 removed the DEBIT and CREDIT corrective types on
 * 1.10.2021, so every corrective kind emits a CORRECTIVE reference. A full
 * storno uses CorrectiveType::ERROR_CORRECTIVE.
 */
enum DocumentKind: string
{
    case CREATE = 'create';
    case ADVANCE = 'advance';
    case CORRECTIVE = 'corrective';
    case CREDIT = 'credit';
    case DEBIT = 'debit';
    case BADDEBT = 'baddebt';

    public function isCorrective(): bool
    {
        return $this->correctiveType() !== null;
    }

    /**
     * The v5 EFI InvType attribute value for this document kind.
     */
    public function invType(): string
    {
        return match ($this) {
            self::ADVANCE => 'ADVANCE',
            self::CORRECTIVE, self::CREDIT, self::DEBIT => 'CORRECTIVE',
            default => 'INVOICE',
        };
    }

    public function correctiveType(): ?CorrectiveType
    {
        return match ($this) {
            self::CORRECTIVE, self::CREDIT, self::DEBIT => CorrectiveType::CORRECTIVE,
            default => null,
        };
    }

    public function isAdvance(): bool
    {
        return $this === self::ADVANCE;
    }

    public function requiresOriginal(): bool
    {
        return $this->isCorrective() || $this === self::BADDEBT;
    }
}
