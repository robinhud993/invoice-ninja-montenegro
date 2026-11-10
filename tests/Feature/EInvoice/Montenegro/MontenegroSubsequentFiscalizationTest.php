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

use ReflectionMethod;
use ReflectionProperty;
use App\Models\MontenegroFiscalLog;
use App\Jobs\Cron\MontenegroFiscalizationCron;
use App\Services\EDocument\Standards\Montenegro\Jobs\SendToCis;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Subsequent fiscalization: invoices issued without a JIKR are retried by the
 * cron within the legally allowed window, stamped with the SubseqDelivType.
 */
class MontenegroSubsequentFiscalizationTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testBuildFailureIsRecordedAsAVisibleFailedLog(): void
    {
        $this->enableMontenegro();

        $invoice = $this->invoice->fresh();

        $job = new SendToCis($invoice->id, $this->company, 'create', null, null, null, null, 'invoice');

        $method = new ReflectionMethod($job, 'recordBuildFailure');
        $method->setAccessible(true);
        $method->invoke($job, $invoice, null, new \RuntimeException('not configured. Missing: e_invoice_certificate'));

        $this->assertDatabaseHas('montenegro_fiscal_logs', [
            'invoice_id' => $invoice->id,
            'status' => MontenegroFiscalLog::STATUS_FAILED,
            'error_message' => 'not configured. Missing: e_invoice_certificate',
        ]);

        $this->assertSame('failed', $invoice->fresh()->backup->e_invoice_status);
    }

    public function testOpenLogWithinTheWindowIsResentWithItsDeliveryType(): void
    {
        $this->enableMontenegro();

        $this->createLog([
            'created_at' => now()->subHours(6),
            'subseq_deliv_type' => 'NOINTERNET',
        ]);

        Bus::fake();

        $this->runCron();

        Bus::assertDispatched(SendToCis::class, function (SendToCis $job) {
            $property = new ReflectionProperty($job, 'subseq');
            $property->setAccessible(true);

            return $property->getValue($job) === 'NOINTERNET';
        });
    }

    public function testLogWithAJikrIsNeverResent(): void
    {
        $this->enableMontenegro();

        $this->createLog([
            'created_at' => now()->subHours(6),
            'jikr' => 'c0ffee00-0000-4000-8000-000000000001',
            'status' => MontenegroFiscalLog::STATUS_SENT,
        ]);

        Bus::fake();

        $this->runCron();

        Bus::assertNotDispatched(SendToCis::class);
    }

    public function testLogOutsideTheRetentionWindowIsNotResent(): void
    {
        $this->enableMontenegro();

        $this->createLog([
            'created_at' => now()->subDays(10),
            'subseq_deliv_type' => 'NOINTERNET',
        ]);

        Bus::fake();

        $this->runCron();

        Bus::assertNotDispatched(SendToCis::class);
    }

    private function runCron(): void
    {
        $cron = new MontenegroFiscalizationCron($this->company->id, 'testing');

        $method = new ReflectionMethod($cron, 'processCompany');
        $method->setAccessible(true);
        $method->invoke($cron, $this->company->fresh());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createLog(array $attributes): MontenegroFiscalLog
    {
        return MontenegroFiscalLog::create(array_merge([
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
                'ordinal' => 5,
                'issue_date_time' => now()->format('Y-m-d\TH:i:sP'),
                'invoice_type' => 'NONCASH',
                'payment_method' => 'ACCOUNT',
                'total' => 100.00,
            ],
        ], $attributes));
    }

    private function enableMontenegro(): void
    {
        $settings = $this->company->settings;
        $settings->e_invoice_type = 'MONTENEGRO';
        $this->company->settings = $settings;
        $this->company->save();

        $this->company = $this->company->fresh();
    }
}
