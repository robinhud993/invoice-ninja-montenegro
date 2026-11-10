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

use App\Models\MontenegroEnu;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\Montenegro;

/**
 * Multi ENU support: a company may register several devices, the invoice can
 * select one, otherwise the default ENU is used.
 */
class MontenegroEnuTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testDefaultEnuIsUsedForCashInvoices(): void
    {
        $this->enableMontenegro(cash: true);

        $this->makeEnu([
            'tcr_code' => 'nn825pg646',
            'business_unit_code' => 'ro861ij638',
            'operator_code' => 'ap514jz098',
            'is_default' => true,
        ]);

        $this->registerInitialDeposit('nn825pg646');

        $xml = $this->build();

        $this->assertStringContainsString('TCRCode="nn825pg646"', $xml);
        $this->assertStringContainsString('BusinUnitCode="ro861ij638"', $xml);
        $this->assertStringContainsString('OperatorCode="ap514jz098"', $xml);
    }

    public function testInvoiceEnuOverrideWinsOverTheDefault(): void
    {
        $this->enableMontenegro(cash: true);

        $this->makeEnu([
            'tcr_code' => 'aa111aa111',
            'business_unit_code' => 'bb111bb111',
            'operator_code' => 'cc111cc111',
            'is_default' => true,
        ]);

        $second = $this->makeEnu([
            'tcr_code' => 'nn825pg646',
            'business_unit_code' => 'ro861ij638',
            'operator_code' => 'ap514jz098',
        ]);

        $this->invoice->service()->assignMontenegroEnu($second);

        $this->registerInitialDeposit('nn825pg646');

        $xml = $this->build();

        $this->assertStringContainsString('TCRCode="nn825pg646"', $xml);
        $this->assertStringContainsString('BusinUnitCode="ro861ij638"', $xml);
        $this->assertStringContainsString('OperatorCode="ap514jz098"', $xml);
        $this->assertStringNotContainsString('aa111aa111', $xml);
    }

    public function testEnuRegistryEndpoints(): void
    {
        $this->enableMontenegro();

        $headers = [
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ];

        $created = $this->withHeaders($headers)->postJson('/api/v1/einvoice/montenegro/enus', [
            'tcr_code' => 'nn825pg646',
            'business_unit_code' => 'ro861ij638',
            'operator_code' => 'ap514jz098',
            'is_default' => true,
        ]);

        $created->assertStatus(200);
        $this->assertSame('nn825pg646', $created->json('data.tcr_code'));

        $this->assertDatabaseHas('montenegro_enus', [
            'company_id' => $this->company->id,
            'tcr_code' => 'nn825pg646',
            'is_default' => true,
            'is_active' => true,
        ]);

        $list = $this->withHeaders($headers)->getJson('/api/v1/einvoice/montenegro/enus');
        $list->assertStatus(200);
        $this->assertCount(1, $list->json('data'));

        $id = $created->json('data.id');

        $this->withHeaders($headers)
            ->postJson('/api/v1/einvoice/montenegro/enus/delete', ['id' => $id])
            ->assertStatus(200);

        $this->assertDatabaseMissing('montenegro_enus', ['tcr_code' => 'nn825pg646']);
    }

    private function build(): string
    {
        $invoice = $this->invoice->fresh()->setRelation('company', $this->company);

        $montenegro = new Montenegro($invoice);
        $montenegro->run();

        return (string) $montenegro->getEnvelope();
    }

    private function makeEnu(array $attributes): MontenegroEnu
    {
        return MontenegroEnu::create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'ENU',
            'software_code' => 'gz434bv927',
            'is_default' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function enableMontenegro(bool $cash = false): void
    {
        $settings = $this->company->settings;
        $settings->e_invoice_type = 'MONTENEGRO';
        $settings->montenegro_tin = '12345678';
        $settings->montenegro_tcr_code = 'zz999zz999';
        $settings->montenegro_business_unit_code = 'zz999zz999';
        $settings->montenegro_operator_code = 'zz999zz999';
        $settings->montenegro_software_code = 'gz434bv927';
        $settings->montenegro_test_mode = true;
        $settings->montenegro_invoice_type = $cash ? 'CASH' : 'NONCASH';
        $settings->montenegro_payment_method = $cash ? 'BANKNOTE' : 'ACCOUNT';
        $settings->montenegro_require_buyer_tin = !$cash;
        $this->company->settings = $settings;
        $this->company->e_invoice_certificate = base64_encode($this->pemBundle());
        $this->company->save();

        $this->company = $this->company->fresh();

        if (!$cash) {
            $client = $this->client;
            $client->vat_number = '12345678';
            $client->saveQuietly();
        }
    }

    private function registerInitialDeposit(string $tcrCode): void
    {
        \App\Models\MontenegroCashDeposit::create([
            'company_id' => $this->company->id,
            'tcr_code' => $tcrCode,
            'operation' => 'INITIAL',
            'cash_amt' => 1000,
            'change_datetime' => now(),
            'status' => 'sent',
        ]);
    }

    private function pemBundle(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(
            ['commonName' => 'Montenegro Enu Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return $keyPem . "\n" . $certPem;
    }
}
