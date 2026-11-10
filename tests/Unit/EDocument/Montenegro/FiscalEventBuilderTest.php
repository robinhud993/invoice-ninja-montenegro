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
use App\Services\EDocument\Standards\Montenegro\Enums\TcrType;
use App\Services\EDocument\Standards\Montenegro\Soap\Envelope;
use App\Services\EDocument\Standards\Montenegro\Xml\Signer;
use App\Services\EDocument\Standards\Montenegro\Enums\CashDepositOperation;
use App\Services\EDocument\Standards\Montenegro\Xml\FiscalEventBuilder;

class FiscalEventBuilderTest extends TestCase
{
    public function testTcrRequestStructure(): void
    {
        $doc = FiscalEventBuilder::tcr(
            requestUuid: '78b37523-3677-416a-8bc0-e0dd77296fc7',
            sendDateTime: '2019-12-02T10:57:01+01:00',
            issuerTin: '02657597',
            businessUnitCode: 'ab123ab123',
            tcrInternalId: '1',
            softwareCode: 'ab123ab123',
            maintainerCode: 'cd456cd456',
            validFrom: '2019-12-05',
            type: TcrType::REGULAR,
        );

        $xml = (string) $doc->saveXML();

        $this->assertStringContainsString('<RegisterTCRRequest', $xml);
        $this->assertStringContainsString('Id="Request"', $xml);
        $this->assertStringContainsString('Version="1"', $xml);
        $this->assertStringContainsString('UUID="78b37523-3677-416a-8bc0-e0dd77296fc7"', $xml);
        $this->assertStringContainsString('IssuerTIN="02657597"', $xml);
        $this->assertStringContainsString('BusinUnitCode="ab123ab123"', $xml);
        $this->assertStringContainsString('TCRIntID="1"', $xml);
        $this->assertStringContainsString('SoftCode="ab123ab123"', $xml);
        $this->assertStringContainsString('MaintainerCode="cd456cd456"', $xml);
        $this->assertStringContainsString('ValidFrom="2019-12-05"', $xml);
        $this->assertStringContainsString('Type="REGULAR"', $xml);
    }

    public function testCashDepositRequestStructure(): void
    {
        $doc = FiscalEventBuilder::cashDeposit(
            requestUuid: '8d216f9a-55bb-445a-be32-30137f11b964',
            sendDateTime: '2019-12-05T14:35:00+01:00',
            issuerTin: '02657597',
            tcrCode: 'np830ym389',
            operation: CashDepositOperation::INITIAL,
            cashAmount: 2000,
            changeDateTime: '2019-12-05T14:35:00+01:00',
        );

        $xml = (string) $doc->saveXML();

        $this->assertStringContainsString('<RegisterCashDepositRequest', $xml);
        $this->assertStringContainsString('Id="Request"', $xml);
        $this->assertStringContainsString('Operation="INITIAL"', $xml);
        $this->assertStringContainsString('CashAmt="2000.00"', $xml);
        $this->assertStringContainsString('TCRCode="np830ym389"', $xml);
        $this->assertStringContainsString('IssuerTIN="02657597"', $xml);
        $this->assertStringContainsString('ChangeDateTime="2019-12-05T14:35:00+01:00"', $xml);
    }

    public function testSignedEnvelopeReferencesTheRequestElement(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(['commonName' => 'EFI Event Test', 'countryName' => 'ME'], $resource, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        $doc = FiscalEventBuilder::tcr(
            requestUuid: '78b37523-3677-416a-8bc0-e0dd77296fc7',
            sendDateTime: '2019-12-02T10:57:01+01:00',
            issuerTin: '02657597',
            businessUnitCode: 'ab123ab123',
            tcrInternalId: '1',
        );

        [$envelope, $requestElement] = Envelope::wrap($doc);

        (new Signer($keyPem, $certPem))->sign($envelope, $requestElement);

        $xml = (string) $envelope->saveXML();

        $this->assertStringContainsString('URI="#Request"', $xml);
        $this->assertStringContainsString('X509Certificate', $xml);
        $this->assertStringContainsString('rsa-sha256', $xml);
    }
}
