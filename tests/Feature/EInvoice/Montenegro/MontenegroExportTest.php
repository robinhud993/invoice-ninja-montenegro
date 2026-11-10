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

use App\Models\MontenegroFiscalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\MontenegroExporter;

/**
 * Offline delivery: signed fiscalization messages are exported for manual
 * submission, reusing the fiscal identity when a record already exists.
 */
class MontenegroExportTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testExportReusesTheOpenFiscalRecord(): void
    {
        $this->enableMontenegro();

        $client = $this->client;
        $client->vat_number = '12345678';
        $client->saveQuietly();

        MontenegroFiscalLog::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'client_id' => $this->client->id,
            'kind' => MontenegroFiscalLog::KIND_CREATE,
            'status' => MontenegroFiscalLog::STATUS_FAILED,
            'iic' => '0E3CB8457E17AE9A4676E3227AAC86F8',
            'total' => 100.00,
            'request_state' => [
                'iic' => '0E3CB8457E17AE9A4676E3227AAC86F8',
                'iic_signature' => str_repeat('A', 512),
                'inv_num' => '5/2026',
                'ordinal' => 5,
                'issue_date_time' => now()->format('Y-m-d\TH:i:sP'),
                'total' => 100.00,
            ],
        ]);

        $result = (new MontenegroExporter($this->company->fresh()))->export([$this->invoice->fresh()]);

        $this->assertSame(1, $result['exported']);
        $this->assertSame([], $result['failed']);

        $xml = $this->readEntry($result['path'], 0);

        $this->assertStringContainsString('InvOrdNum="5"', $xml);
        $this->assertStringContainsString('IIC="0E3CB8457E17AE9A4676E3227AAC86F8"', $xml);
        $this->assertStringContainsString('SubseqDelivType="NOINTERNET"', $xml);
        $this->assertStringContainsString('URI="#Request"', $xml);

        @unlink($result['path']);
    }

    public function testExportReservesAnOrdinalForANewInvoice(): void
    {
        $this->enableMontenegro();

        $client = $this->client;
        $client->vat_number = '12345678';
        $client->saveQuietly();

        $invoice = $this->invoice->fresh();
        $this->assertSame(0, $invoice->montenegro_fiscal_logs()->count());

        $result = (new MontenegroExporter($this->company->fresh()))->export([$invoice]);

        $this->assertSame(1, $result['exported']);

        $log = $invoice->fresh()->montenegro_fiscal_logs()->first();

        $this->assertNotNull($log);
        $this->assertSame(MontenegroFiscalLog::STATUS_PENDING, $log->status);
        $this->assertSame(1, (int) ($log->request_state['ordinal'] ?? 0));

        @unlink($result['path']);
    }

    public function testExportEndpointDownloadsAZipArchive(): void
    {
        $this->enableMontenegro();

        $client = $this->client;
        $client->vat_number = '12345678';
        $client->saveQuietly();

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get('/api/v1/einvoice/montenegro/export');

        $response->assertStatus(200);
        $response->assertDownload();
    }

    private function readEntry(string $path, int $index): string
    {
        $zip = new \ZipArchive();
        $zip->open($path);
        $content = (string) $zip->getFromIndex($index);
        $zip->close();

        return $content;
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
        $settings->montenegro_invoice_type = 'NONCASH';
        $settings->montenegro_payment_method = 'ACCOUNT';
        $settings->montenegro_require_buyer_tin = true;
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
            ['commonName' => 'Montenegro Export Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return $keyPem . "\n" . $certPem;
    }
}
