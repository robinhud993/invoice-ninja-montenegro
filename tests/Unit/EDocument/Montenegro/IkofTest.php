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
use App\Services\EDocument\Standards\Montenegro\Ikof;

class IkofTest extends TestCase
{
    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($resource, 'Unable to generate a test RSA key.');

        openssl_pkey_export($resource, $privateKey);
        $this->privateKey = $privateKey;
    }

    public function testConcatenationUsesPipeSeparatorInTheDocumentedOrder(): void
    {
        $payload = Ikof::concatenate(
            '12345678',
            '2019-06-12T17:05:43+02:00',
            '9952',
            'bb123bb123',
            'cc123cc123',
            'ss123ss123',
            '99.01',
        );

        $this->assertSame(
            '12345678|2019-06-12T17:05:43+02:00|9952|bb123bb123|cc123cc123|ss123ss123|99.01',
            $payload
        );
    }

    public function testGenerateProducesA32CharacterIicAnd512CharacterSignature(): void
    {
        $payload = Ikof::concatenate(
            '12345678',
            '2019-06-12T17:05:43+02:00',
            '9952',
            'bb123bb123',
            'cc123cc123',
            'ss123ss123',
            '99.01',
        );

        $ikof = Ikof::generate($payload, $this->privateKey);

        $this->assertMatchesRegularExpression('/^[0-9A-F]{32}$/', $ikof->iic);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{512}$/', $ikof->signature);
        $this->assertSame($payload, $ikof->payload);
    }

    public function testIicIsTheMd5OfTheRawSignature(): void
    {
        $payload = '12345678|2019-06-12T17:05:43+02:00|9952|bb123bb123|cc123cc123|ss123ss123|99.01';

        $ikof = Ikof::generate($payload, $this->privateKey);

        $expected = strtoupper(bin2hex(md5(hex2bin($ikof->signature), true)));

        $this->assertSame($expected, $ikof->iic);
    }

    public function testVerifyAcceptsAMatchingSignature(): void
    {
        $payload = '12345678|2019-06-12T17:05:43+02:00|9952|bb123bb123|cc123cc123|ss123ss123|99.01';

        $ikof = Ikof::generate($payload, $this->privateKey);

        $this->assertTrue($ikof->verify($this->privateKey));
    }
}
