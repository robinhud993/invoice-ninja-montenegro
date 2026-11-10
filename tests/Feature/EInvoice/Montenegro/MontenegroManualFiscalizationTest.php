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

use App\Models\Webhook;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\Jobs\SendToCis;

/**
 * Verifies manual fiscalization: automatic fiscalization can be disabled and the
 * invoice can then be fiscalized explicitly through the fiscalize action, and
 * that the fiscalization status is exposed on the invoice API.
 *
 * Bus::fake() is used instead of Queue::fake(): the API TokenAuth middleware
 * resolves the queue manager, which QueueFake does not support.
 */
class MontenegroManualFiscalizationTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testAutoFiscalizeDisabledDoesNotDispatchOnSentEvent(): void
    {
        $this->enableMontenegro(auto_fiscalize: false);

        Bus::fake();

        $this->invoice->sendEvent(Webhook::EVENT_SENT_INVOICE, 'client');

        Bus::assertNotDispatched(SendToCis::class);
    }

    public function testAutoFiscalizeEnabledDispatchesOnSentEvent(): void
    {
        $this->enableMontenegro(auto_fiscalize: true);

        Bus::fake();

        $this->invoice->sendEvent(Webhook::EVENT_SENT_INVOICE, 'client');

        Bus::assertDispatched(SendToCis::class);
    }

    public function testFiscalizeActionDispatchesJob(): void
    {
        $this->enableMontenegro(auto_fiscalize: false);

        Bus::fake();

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get("/api/v1/invoices/{$this->invoice->hashed_id}/fiscalize");

        $response->assertStatus(200);

        Bus::assertDispatched(SendToCis::class);

        // sendMontenegro flags the invoice as pending before dispatching the job.
        $this->assertSame('pending', $this->invoice->fresh()->backup->e_invoice_status);
    }

    public function testFiscalizeActionRejectsNonMontenegroCompany(): void
    {
        Bus::fake();

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get("/api/v1/invoices/{$this->invoice->hashed_id}/fiscalize");

        $response->assertStatus(400);

        Bus::assertNotDispatched(SendToCis::class);
    }

    public function testTransformerExposesEInvoiceStatus(): void
    {
        $this->enableMontenegro(auto_fiscalize: true);

        $this->invoice->backup->e_invoice_status = 'sent';
        $this->invoice->saveQuietly();

        $response = $this->withHeaders([
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ])->get("/api/v1/invoices/{$this->invoice->hashed_id}");

        $response->assertStatus(200);
        $this->assertSame('sent', $response->json('data.e_invoice_status'));
    }

    private function enableMontenegro(bool $auto_fiscalize): void
    {
        $settings = $this->company->settings;
        $settings->e_invoice_type = 'MONTENEGRO';
        $settings->montenegro_auto_fiscalize = $auto_fiscalize;
        $settings->montenegro_tin = '12345678';
        $settings->montenegro_tcr_code = 'nn825pg646';
        $settings->montenegro_business_unit_code = 'ro861ij638';
        $settings->montenegro_operator_code = 'ap514jz098';
        $settings->montenegro_software_code = 'gz434bv927';
        $settings->montenegro_test_mode = true;
        $this->company->settings = $settings;
        $this->company->save();

        // Reload so the once() memoized settings on the previous instances are not reused.
        $this->company = $this->company->fresh();
        $this->invoice = $this->invoice->fresh();
        $this->invoice->setRelation('company', $this->company);

        $this->invoice->backup->guid = '';
        $this->invoice->saveQuietly();
    }
}
