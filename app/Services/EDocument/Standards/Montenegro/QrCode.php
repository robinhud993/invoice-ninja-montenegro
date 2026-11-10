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

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * QrCode
 *
 * Builds the invoice verification QR code described in the Fiscalization service
 * technical specification, section 3.7.7.
 *
 * URL: {base}/verify?iic={iic}&tin={tin}&crtd={created}&ord={ordinal}&bu={bu}&cr={tcr}&sw={sw}&prc={price}
 *
 * The QR code must use an error correction level of at least M (15% recovery).
 */
class QrCode
{
    // v4.2 changelog section 3.7.7: the production verification host changed
    // from efi.tax.gov.me to mapr.tax.gov.me.
    public const PRODUCTION_BASE_URL = 'https://mapr.tax.gov.me/ic/#/verify';
    public const TEST_BASE_URL = 'https://efitest.tax.gov.me/ic/#/verify';

    public function __construct(private bool $testMode = false) {}

    public function baseUrl(): string
    {
        return $this->testMode ? self::TEST_BASE_URL : self::PRODUCTION_BASE_URL;
    }

    public function url(
        string $iic,
        string $tin,
        string $created,
        int|string $ordinal,
        string $businessUnitCode,
        string $tcrCode,
        string $softwareCode,
        string $price,
    ): string {
        return $this->baseUrl() . '?' . http_build_query([
            'iic' => $iic,
            'tin' => $tin,
            'crtd' => $created,
            'ord' => (string) $ordinal,
            'bu' => $businessUnitCode,
            'cr' => $tcrCode,
            'sw' => $softwareCode,
            'prc' => $price,
        ]);
    }

    /**
     * Returns the PNG binary of the QR code, or an empty string when generation fails.
     */
    public function png(string $url, int $size = 300, int $margin = 10): string
    {
        try {
            $result = Builder::create()
                ->writer(new PngWriter())
                ->data($url)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
                ->size($size)
                ->margin($margin)
                ->build();

            return $result->getString();
        } catch (\Throwable $e) {
            nlog('MONTENEGRO FISCALIZATION ERROR: [qr] ' . $e->getMessage());

            return '';
        }
    }

    /**
     * Returns a data URI suitable for embedding in a template.
     */
    public function dataUri(string $url, int $size = 300, int $margin = 10): string
    {
        $png = $this->png($url, $size, $margin);

        return $png === '' ? '' : 'data:image/png;base64,' . base64_encode($png);
    }
}
