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

use App\Models\Invoice;
use App\Models\MontenegroFiscalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\Montenegro;

/**
 * An advance (avans) already paid is reported on the final invoice as a
 * separate ADVANCE PayMethod, with the remainder settled by the configured
 * method.
 */
class MontenegroAdvanceTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testAdvanceIsReportedAsASeparatePayMethod(): void
    {
        $this->enableMontenegro();

        $client = $this->client;
        $client->vat_number = '12345678';
        $client->saveQuietly();

        // The advance invoice, fiscalized separately for 40.00.
        $advance = $this->invoice;
        $advance->amount = 40.00;
        $advance->backup->guid = 'c0ffee00-0000-4000-8000-000000000040';
        $advance->saveQuietly();

        MontenegroFiscalLog::create([
            'company_id' => $this->company->id,
            'invoice_id' => $advance->id,
            'client_id' => $this->client->id,
            'kind' => MontenegroFiscalLog::KIND_ADVANCE,
            'status' => MontenegroFiscalLog::STATUS_SENT,
            'iic' => '0E3CB8457E17AE9A4676E3227AAC86F8',
            'jikr' => 'c0ffee00-0000-4000-8000-000000000040',
            'total' => 40.00,
            'sent_at' => now(),
            'request_state' => [
                'inv_num' => '7/2026/nn825pg646',
                'ordinal' => 7,
                'mode' => 'TEST',
                'tcr' => 'nn825pg646',
                'operator' => 'ap514jz098',
                'total' => 40.00,
                'issue_date_time' => now()->format('Y-m-d\TH:i:sP'),
            ],
        ]);

        // The final invoice which applies the advance.
        $final = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
        ]);

        $final = $final->fresh();
        $final->service()->linkMontenegroAdvance($advance->fresh());

        $final = $final->fresh();
        $this->assertSame('0E3CB8457E17AE9A4676E3227AAC86F8', $final->backup->advance_iic);
        $this->assertSame(40.0, $final->backup->advance_amount);

        $montenegro = new Montenegro($final->setRelation('company', $this->company));
        $montenegro->run();

        $xml = (string) $montenegro->getEnvelope();

        $total = $montenegro->getTotals()->total;
        $this->assertGreaterThan(40.0, $total);

        $remaining = number_format($total - 40.0, 2, '.', '');

        $this->assertSame(2, substr_count($xml, '<PayMethod '));
        $this->assertStringContainsString('Type="ADVANCE"', $xml);
        $this->assertStringContainsString('Amt="40.00"', $xml);
        $this->assertStringContainsString('Type="ACCOUNT"', $xml);
        $this->assertStringContainsString('Amt="' . $remaining . '"', $xml);
    }

    public function testLinkingAnUnfiscalizedAdvanceIsRejected(): void
    {
        $this->enableMontenegro();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has not been fiscalized');

        $this->invoice->service()->linkMontenegroAdvance($this->invoice);
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
        $settings->montenegro_require_buyer_tin = true;
        $settings->montenegro_invoice_type = 'NONCASH';
        $settings->montenegro_payment_method = 'ACCOUNT';
        // The invoice under test carries VAT, so the issuer is in the VAT system.
        $settings->montenegro_is_in_vat = true;
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
            ['commonName' => 'Montenegro Advance Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return $keyPem . "\n" . $certPem;
    }
}
