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
use App\Services\EDocument\Standards\Montenegro\Soap\ResponseProcessor;

class ResponseProcessorTest extends TestCase
{
    private const SUCCESS = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Header/>
    <SOAP-ENV:Body>
        <ns2:RegisterInvoiceResponse Id="Response" Version="1" xmlns:ns2="https://efi.tax.gov.me/fs/schema">
            <ns2:Header RequestUUID="8d216f9a-55bb-445a-be32-30137f11b964" SendDateTime="2019-11-27T09:41:12+01:00" UUID="f8bcb5ae-59fb-41ac-9011-f4db86bbce26"/>
            <ns2:FIC>a592e7ec-9517-4f02-8d54-ac965f679a8c</ns2:FIC>
        </ns2:RegisterInvoiceResponse>
    </SOAP-ENV:Body>
</SOAP-ENV:Envelope>
XML;

    private const FAULT = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Header/>
    <SOAP-ENV:Body>
        <SOAP-ENV:Fault>
            <faultcode>SOAP-ENV:CLIENT</faultcode>
            <faultstring xml:lang="en">Validation failed with digest wrong.</faultstring>
            <detail>
                <code>30</code>
                <requestUUID>8d216f9a-55bb-445a-be32-30137f11b964</requestUUID>
                <responseUUID>9416dcca-499a-4724-933d-40d115ea4fc7</responseUUID>
            </detail>
        </SOAP-ENV:Fault>
    </SOAP-ENV:Body>
</SOAP-ENV:Envelope>
XML;

    public function testSuccessfulResponseYieldsJikr(): void
    {
        $result = (new ResponseProcessor())->process(self::SUCCESS, 200);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['fault']);
        $this->assertSame('a592e7ec-9517-4f02-8d54-ac965f679a8c', $result['jikr']);
        $this->assertSame('8d216f9a-55bb-445a-be32-30137f11b964', $result['request_uuid']);
        $this->assertSame('f8bcb5ae-59fb-41ac-9011-f4db86bbce26', $result['response_uuid']);
    }

    public function testFaultResponseYieldsErrorCode(): void
    {
        $result = (new ResponseProcessor())->process(self::FAULT, 500);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['fault']);
        $this->assertSame(30, $result['error_code']);
        $this->assertStringContainsString('digest', $result['message']);
        $this->assertSame(500, $result['http_status']);
    }

    public function testEmptyResponseIsNotSuccessful(): void
    {
        $result = (new ResponseProcessor())->process('', 0);

        $this->assertFalse($result['success']);
        $this->assertSame('Empty response from the fiscalization service.', $result['message']);
    }

    public function testErrorCodesAreDescribed(): void
    {
        $this->assertStringContainsString('Digest', ResponseProcessor::describeErrorCode(30));
        $this->assertStringContainsString('Internal server', ResponseProcessor::describeErrorCode(900));
    }

    public function testTcrCodeIsParsedFromATcrResponse(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Body>
        <ns2:RegisterTCRResponse Id="Response" Version="1" xmlns:ns2="https://efi.tax.gov.me/fs/schema">
            <ns2:Header RequestUUID="07986698-1502-4150-9520-1918a5bbbf49" SendDateTime="2019-11-27T09:49:22+01:00" UUID="12839912-75b6-4cd4-ba2d-fca3e6395226"/>
            <ns2:TCRCode>ab123ab123</ns2:TCRCode>
        </ns2:RegisterTCRResponse>
    </SOAP-ENV:Body>
</SOAP-ENV:Envelope>
XML;

        $result = (new ResponseProcessor())->process($xml, 200, 'TCRCode');

        $this->assertTrue($result['success']);
        $this->assertSame('ab123ab123', $result['code']);
        $this->assertNull($result['jikr']);
    }

    public function testFcdcIsParsedFromACashDepositResponse(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Body>
        <ns2:RegisterCashDepositResponse Id="Response" Version="1" xmlns:ns2="https://efi.tax.gov.me/fs/schema">
            <ns2:Header RequestUUID="8d216f9a-55bb-445a-be32-30137f11b964" SendDateTime="2019-11-27T09:41:12+01:00" UUID="f8bcb5ae-59fb-41ac-9011-f4db86bbce26"/>
            <ns2:FCDC>c8a197f1-b7dc-4e82-8729-4a506aecf9b9</ns2:FCDC>
        </ns2:RegisterCashDepositResponse>
    </SOAP-ENV:Body>
</SOAP-ENV:Envelope>
XML;

        $result = (new ResponseProcessor())->process($xml, 200, 'FCDC');

        $this->assertTrue($result['success']);
        $this->assertSame('c8a197f1-b7dc-4e82-8729-4a506aecf9b9', $result['code']);
        $this->assertNull($result['jikr']);
    }
}
