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

use App\Models\Credit;
use App\Models\Webhook;
use App\Models\MontenegroFiscalLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\Jobs\SendToCis;

/**
 * Native Invoice Ninja credit notes are corrective documents and must be
 * fiscalized through the same pipeline as invoices.
 */
class MontenegroCreditFiscalizationTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testCreditFiscalizationDispatchesWithTheCreditEntityType(): void
    {
        $this->enableMontenegro();

        $credit = $this->makeCredit();

        Bus::fake();

        $credit->service()->sendMontenegro();

        Bus::assertDispatched(SendToCis::class, function (SendToCis $job) use ($credit): bool {
            $entityType = new \ReflectionProperty($job, 'entity_type');
            $entityType->setAccessible(true);
            $entityId = new \ReflectionProperty($job, 'invoice_id');
            $entityId->setAccessible(true);

            return $entityType->getValue($job) === 'credit'
                && $entityId->getValue($job) === $credit->id;
        });
    }

    public function testSentCreditEventDispatchesFiscalization(): void
    {
        $this->enableMontenegro();

        $credit = $this->makeCredit();

        Bus::fake();

        $credit->sendEvent(Webhook::EVENT_SENT_CREDIT, 'client');

        Bus::assertDispatched(SendToCis::class);
    }

    public function testCreditIsLockedOnceAFiscalRecordExists(): void
    {
        $this->enableMontenegro();

        $credit = $this->makeCredit();

        MontenegroFiscalLog::create([
            'company_id' => $this->company->id,
            'invoice_id' => null,
            'credit_id' => $credit->id,
            'client_id' => $this->client->id,
            'kind' => MontenegroFiscalLog::KIND_CREDIT,
            'status' => MontenegroFiscalLog::STATUS_REJECTED,
            'iic' => '0E3CB8457E17AE9A4676E3227AAC86F8',
            'total' => 100.00,
            'request_state' => ['inv_num' => '5/2026/nn825pg646', 'ordinal' => 5],
        ]);

        $this->assertTrue($credit->fresh()->montenegroLocked());
        $this->assertFalse($credit->fresh()->hasSentCis());
    }

    private function makeCredit(): Credit
    {
        return Credit::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'amount' => 100,
        ]);
    }

    private function enableMontenegro(): void
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
        $this->company->settings = $settings;
        $this->company->save();

        $this->company = $this->company->fresh();
    }
}
