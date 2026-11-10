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
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\Montenegro;
use App\Services\EDocument\Standards\Montenegro\Jobs\SendToCis;

/**
 * Compliance guards: fiscalized invoices are immutable, cannot be fiscalized
 * twice, manual resends reuse the open fiscal record, and non-cash invoices
 * require a buyer tax number.
 */
class MontenegroComplianceGuardsTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testFiscalizedInvoiceIsNotSentAgain(): void
    {
        $this->enableMontenegro();

        $this->invoice->backup->guid = 'c0ffee00-0000-4000-8000-000000000001';
        $this->invoice->saveQuietly();

        Bus::fake();

        $this->invoice->fresh()->service()->sendMontenegro();

        Bus::assertNotDispatched(SendToCis::class);
    }

    public function testFiscalizeActionRejectsAlreadyFiscalizedInvoice(): void
    {
        $this->enableMontenegro();

        $this->invoice->backup->guid = 'c0ffee00-0000-4000-8000-000000000001';
        $this->invoice->saveQuietly();

        Bus::fake();

        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get("/api/v1/invoices/{$this->invoice->hashed_id}/fiscalize")
            ->assertStatus(400);

        Bus::assertNotDispatched(SendToCis::class);
    }

    public function testFiscalizeActionRejectsWhenFiscalizationIsInProgress(): void
    {
        $this->enableMontenegro();

        $this->createLog(MontenegroFiscalLog::STATUS_PENDING, null);

        Bus::fake();

        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get("/api/v1/invoices/{$this->invoice->hashed_id}/fiscalize")
            ->assertStatus(400);

        Bus::assertNotDispatched(SendToCis::class);
    }

    public function testManualResendReusesTheOpenFiscalRecord(): void
    {
        $this->enableMontenegro();

        $log = $this->createLog(MontenegroFiscalLog::STATUS_FAILED, '0E3CB8457E17AE9A4676E3227AAC86F8');

        Bus::fake();

        $this->invoice->fresh()->service()->sendMontenegro();

        Bus::assertDispatched(SendToCis::class, function (SendToCis $job) use ($log): bool {
            $property = new \ReflectionProperty($job, 'log_id');
            $property->setAccessible(true);

            return $property->getValue($job) === $log->id;
        });
    }

    public function testInvoiceIsLockedOnceAFiscalRecordExists(): void
    {
        $this->enableMontenegro();

        $this->createLog(MontenegroFiscalLog::STATUS_REJECTED, '0E3CB8457E17AE9A4676E3227AAC86F8');

        $this->assertTrue($this->invoice->fresh()->montenegroLocked());
    }

    public function testUpdateIsRejectedForLockedFiscalizedInvoice(): void
    {
        $this->enableMontenegro();

        $this->invoice->backup->guid = 'c0ffee00-0000-4000-8000-000000000001';
        $this->invoice->saveQuietly();

        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
            'Accept' => 'application/json',
        ])->put("/api/v1/invoices/{$this->invoice->hashed_id}", [
            'public_notes' => 'attempted change',
        ])->assertStatus(422)->assertJsonValidationErrors('status_id');
    }

    public function testNonCashInvoiceWithoutBuyerTaxNumberIsRejected(): void
    {
        $this->enableMontenegro(with_certificate: true);

        $client = $this->invoice->client;
        $client->vat_number = '';
        $client->id_number = '';
        $client->saveQuietly();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('buyer tax number');

        (new Montenegro($this->invoice->fresh()->setRelation('company', $this->company)))->run();
    }

    public function testExemptItemEmitsExemptionCode(): void
    {
        $this->enableMontenegro(with_certificate: true);

        $client = $this->invoice->client;
        $client->vat_number = '12345678';
        $client->saveQuietly();

        $invoice = $this->invoice->fresh();
        $items = $invoice->line_items;
        $items[0]->custom_value4 = 'VAT_CL27';
        $items[0]->tax_rate1 = 0;
        $items[0]->tax_rate2 = 0;
        $items[0]->tax_rate3 = 0;
        $items[0]->tax_amount = 0;
        $invoice->line_items = $items;
        $invoice->saveQuietly();

        $montenegro = new Montenegro($this->invoice->fresh()->setRelation('company', $this->company));
        $montenegro->run();

        $xml = (string) $montenegro->getEnvelope();

        $this->assertStringContainsString('EX="VAT_CL27"', $xml);
        // The item level VAT_CLxx code must never be emitted as a SameTax value.
        $this->assertStringNotContainsString('ExemptFromVAT=', $xml);
    }

    public function testCorrectiveFiscalizeUsesTheOriginalReference(): void
    {
        $this->enableMontenegro();

        $original = $this->invoice;

        $this->createLog(MontenegroFiscalLog::STATUS_SENT, '0E3CB8457E17AE9A4676E3227AAC86F8');

        $corrective = \App\Models\Invoice::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'amount' => -100,
        ]);

        $corrective->backup->parent_invoice_id = $original->hashed_id;
        $corrective->backup->parent_invoice_number = $original->number;
        $corrective->saveQuietly();

        Bus::fake();

        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get("/api/v1/invoices/{$corrective->hashed_id}/fiscalize?kind=corrective")
            ->assertStatus(200);

        Bus::assertDispatched(SendToCis::class, function (SendToCis $job): bool {
            $property = new \ReflectionProperty($job, 'corrective');
            $property->setAccessible(true);
            $reference = $property->getValue($job);

            return is_array($reference)
                && ($reference['iic'] ?? null) === '0E3CB8457E17AE9A4676E3227AAC86F8'
                && ($reference['type'] ?? null) === 'CORRECTIVE';
        });
    }

    public function testCorrectiveFiscalizeWithoutAnOriginalIsRejected(): void
    {
        $this->enableMontenegro();

        Bus::fake();

        $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get("/api/v1/invoices/{$this->invoice->hashed_id}/fiscalize?kind=debit")
            ->assertStatus(400);

        Bus::assertNotDispatched(SendToCis::class);
    }

    private function createLog(string $status, ?string $iic): MontenegroFiscalLog
    {
        return MontenegroFiscalLog::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'client_id' => $this->client->id,
            'kind' => MontenegroFiscalLog::KIND_CREATE,
            'status' => $status,
            'iic' => $iic,
            'total' => $this->invoice->amount,
            'request_state' => [
                'inv_num' => '5/2026/nn825pg646',
                'ordinal' => 5,
                'mode' => 'TEST',
                'tcr' => 'nn825pg646',
                'operator' => 'ap514jz098',
            ],
        ]);
    }

    private function enableMontenegro(bool $with_certificate = false): void
    {
        $settings = $this->company->settings;
        $settings->e_invoice_type = 'MONTENEGRO';
        $settings->montenegro_auto_fiscalize = true;
        $settings->montenegro_tin = '12345678';
        $settings->montenegro_tcr_code = 'nn825pg646';
        $settings->montenegro_business_unit_code = 'ro861ij638';
        $settings->montenegro_operator_code = 'ap514jz098';
        $settings->montenegro_software_code = 'gz434bv927';
        $settings->montenegro_test_mode = true;
        $settings->montenegro_require_buyer_tin = true;
        $this->company->settings = $settings;

        if ($with_certificate) {
            $this->company->e_invoice_certificate = base64_encode($this->pemBundle());
        }

        $this->company->save();

        // Reload so the once() memoized settings on the previous instances are not reused.
        $this->company = $this->company->fresh();
        $this->invoice = $this->invoice->fresh();
        $this->invoice->setRelation('company', $this->company);

        $this->invoice->backup->guid = '';
        $this->invoice->saveQuietly();
    }

    private function pemBundle(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(
            ['commonName' => 'Montenegro Guard Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return $keyPem . "\n" . $certPem;
    }
}
