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

namespace Tests\Unit\EDocument\Montenegro;

use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\QrCode;

class QrCodeTest extends TestCase
{
    public function testTestEnvironmentBaseUrl(): void
    {
        $this->assertSame(QrCode::TEST_BASE_URL, (new QrCode(true))->baseUrl());
    }

    public function testProductionEnvironmentBaseUrl(): void
    {
        $this->assertSame(QrCode::PRODUCTION_BASE_URL, (new QrCode(false))->baseUrl());
    }

    public function testUrlContainsAllDocumentedQueryParameters(): void
    {
        $url = (new QrCode(true))->url(
            iic: 'EA26D5BE7F45827026108F825A8A512B',
            tin: '91806031',
            created: '2019-09-26T13:50:13+01:00',
            ordinal: 6,
            businessUnitCode: 'bg517kw842',
            tcrCode: 'xb131ap287',
            softwareCode: 'gz434bv927',
            price: '199.00',
        );

        $this->assertStringStartsWith(QrCode::TEST_BASE_URL, $url);
        $this->assertStringContainsString('iic=EA26D5BE7F45827026108F825A8A512B', $url);
        $this->assertStringContainsString('tin=91806031', $url);
        $this->assertStringContainsString('ord=6', $url);
        $this->assertStringContainsString('bu=bg517kw842', $url);
        $this->assertStringContainsString('cr=xb131ap287', $url);
        $this->assertStringContainsString('sw=gz434bv927', $url);
        $this->assertStringContainsString('prc=199.00', $url);
        $this->assertStringContainsString('crtd=2019-09-26T13', $url);
    }

    public function testPngIsGeneratedWithMinimumErrorCorrection(): void
    {
        $png = (new QrCode(true))->png('https://efitest.tax.gov.me/ic/#/verify?iic=ABC');

        $this->assertNotSame('', $png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }
}
