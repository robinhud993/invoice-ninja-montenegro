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

namespace Tests\Feature\EInvoice\Montenegro;

use App\Models\MontenegroCashDeposit;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Covers the registerTCR and registerCashDeposit endpoints end to end with a
 * faked CIS response.
 */
class MontenegroRegistrationEndpointsTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();

        $this->enableMontenegro();
    }

    public function testRegisterTcrStoresTheReturnedCode(): void
    {
        Http::fake(['*' => Http::response($this->tcrResponse(), 200)]);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/einvoice/montenegro/register_tcr', [
            'tcr_internal_id' => '1',
            'business_unit_code' => 'ro861ij638',
        ]);

        $response->assertStatus(200);
        $this->assertSame('nn825pg646', $response->json('code'));
        $this->assertSame('nn825pg646', $this->company->fresh()->getSetting('montenegro_tcr_code'));
    }

    public function testRemoveCertificateClearsTheStoredCertificate(): void
    {
        $this->assertNotNull($this->company->e_invoice_certificate);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/einvoice/montenegro/setup', [
            'remove_certificate' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('has_certificate', false);

        $this->assertNull($this->company->fresh()->e_invoice_certificate);
        $this->assertNull($this->company->fresh()->e_invoice_certificate_passphrase);
    }

    public function testCashDepositIsRecordedOnSuccess(): void
    {
        Http::fake(['*' => Http::response($this->cashDepositResponse(), 200)]);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/einvoice/montenegro/cash_deposit', [
            'operation' => 'INITIAL',
            'cash_amount' => 2000,
        ]);

        $response->assertStatus(200);
        $this->assertSame('c8a197f1-b7dc-4e82-8729-4a506aecf9b9', $response->json('code'));

        $this->assertDatabaseHas('montenegro_cash_deposits', [
            'company_id' => $this->company->id,
            'operation' => 'INITIAL',
            'status' => MontenegroCashDeposit::STATUS_SENT,
            'fcdc' => 'c8a197f1-b7dc-4e82-8729-4a506aecf9b9',
        ]);
    }

    public function testCashDepositFailureIsRecorded(): void
    {
        Http::fake(['*' => Http::response($this->faultResponse(50), 200)]);

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->postJson('/api/v1/einvoice/montenegro/cash_deposit', [
            'operation' => 'WITHDRAW',
            'cash_amount' => 100,
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('montenegro_cash_deposits', [
            'company_id' => $this->company->id,
            'operation' => 'WITHDRAW',
            'status' => MontenegroCashDeposit::STATUS_FAILED,
            'error_code' => 50,
        ]);
    }

    private function tcrResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Body>
        <ns2:RegisterTCRResponse Id="Response" Version="1" xmlns:ns2="https://efi.tax.gov.me/fs/schema">
            <ns2:Header RequestUUID="07986698-1502-4150-9520-1918a5bbbf49" SendDateTime="2019-11-27T09:49:22+01:00" UUID="12839912-75b6-4cd4-ba2d-fca3e6395226"/>
            <ns2:TCRCode>nn825pg646</ns2:TCRCode>
        </ns2:RegisterTCRResponse>
    </SOAP-ENV:Body>
</SOAP-ENV:Envelope>
XML;
    }

    private function cashDepositResponse(): string
    {
        return <<<'XML'
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
    }

    private function faultResponse(int $code): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Body>
        <SOAP-ENV:Fault>
            <faultcode>SOAP-ENV:CLIENT</faultcode>
            <faultstring xml:lang="en">Cash amount cannot be negative for an INITIAL operation.</faultstring>
            <detail><code>{$code}</code></detail>
        </SOAP-ENV:Fault>
    </SOAP-ENV:Body>
</SOAP-ENV:Envelope>
XML;
    }

    private function enableMontenegro(): void
    {
        $settings = $this->company->settings;
        $settings->e_invoice_type = 'MONTENEGRO';
        $settings->montenegro_tin = '12345678';
        $settings->montenegro_tcr_code = 'nn825pg646';
        $settings->montenegro_business_unit_code = 'ro861ij638';
        $settings->montenegro_operator_code = 'ap514jz098';
        $settings->montenegro_software_code = 'gz434bv927';
        $settings->montenegro_test_mode = true;
        $this->company->settings = $settings;
        $this->company->e_invoice_certificate = base64_encode($this->pemBundle());
        $this->company->save();

        $this->company = $this->company->fresh();
    }

    private function pemBundle(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(
            ['commonName' => 'Montenegro Endpoint Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return $keyPem . "\n" . $certPem;
    }
}
