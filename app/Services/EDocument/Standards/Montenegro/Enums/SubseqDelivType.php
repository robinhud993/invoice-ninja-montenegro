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
 * EFI SubseqDelivTypeSType.
 *
 * Set on the request Header when an invoice is delivered after issuance, for
 * example when the ENU was offline or the fiscalization service was unavailable.
 * Article 17 of the Fiscalisation Law allows 2 days to resend in the NOINTERNET
 * case, 5 days for a service failure.
 */
enum SubseqDelivType: string
{
    case NOINTERNET = 'NOINTERNET';
    case BOUNDBOOK = 'BOUNDBOOK';
    case SERVICE = 'SERVICE';
    case TECHNICALERROR = 'TECHNICALERROR';
    case BUSINESSNEED = 'BUSINESSNEED';

    public function label(): string
    {
        return match ($this) {
            self::NOINTERNET => 'No internet available',
            self::BOUNDBOOK => 'Bound book fallback',
            self::SERVICE => 'Fiscalization service failure',
            self::TECHNICALERROR => 'Temporary technical error',
            self::BUSINESSNEED => 'Business need',
        };
    }

    /**
     * The legally allowed resend window in days for this delivery type.
     */
    public function retryWindowDays(): int
    {
        return match ($this) {
            self::NOINTERNET => 2,
            self::SERVICE, self::BOUNDBOOK, self::TECHNICALERROR, self::BUSINESSNEED => 5,
        };
    }

    public static function fromSetting(?string $value): ?self
    {
        if (is_null($value)) {
            return null;
        }

        return self::tryFrom(strtoupper(trim($value)));
    }
}
