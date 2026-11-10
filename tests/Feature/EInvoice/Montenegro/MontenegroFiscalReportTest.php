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

/**
 * Covers the Montenegro fiscalization reporting endpoint: summary totals,
 * breakdowns, backlog window and timeline.
 */
class MontenegroFiscalReportTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testReportSummarisesTheFiscalJournal(): void
    {
        $this->seedJournal();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/report');

        $response->assertStatus(200)
            ->assertJsonPath('totals.records', 4)
            ->assertJsonPath('totals.fiscalized', 2)
            ->assertJsonPath('totals.unfiscalized', 2)
            ->assertJsonPath('retry_window_days', 5);

        $this->assertEquals(0.5, $response->json('totals.success_rate'));
        $this->assertEquals(220.0, $response->json('totals.amount_total'));
        $this->assertEquals(140.0, $response->json('totals.amount_fiscalized'));
        $this->assertEquals(80.0, $response->json('totals.amount_unfiscalized'));
    }

    public function testReportBreaksDownByKindAndStatus(): void
    {
        $this->seedJournal();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/report');

        $response->assertStatus(200);

        $byKind = collect($response->json('by_kind'))->keyBy('kind');
        $this->assertSame(3, $byKind['create']['records']);
        $this->assertSame(1, $byKind['create']['fiscalized']);
        $this->assertEquals(180.0, $byKind['create']['amount_total']);
        $this->assertSame(1, $byKind['advance']['records']);

        $byStatus = collect($response->json('by_status'))->keyBy('status');
        $this->assertSame(2, $byStatus['sent']['records']);
        $this->assertSame(2, $byStatus['failed']['records']);
        $this->assertEquals(140.0, $byStatus['sent']['amount_total']);
    }

    public function testReportReportsTheOpenBacklog(): void
    {
        $this->seedJournal();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/report');

        $response->assertStatus(200)
            ->assertJsonPath('backlog.open', 2)
            ->assertJsonPath('backlog.within_window', 1)
            ->assertJsonPath('backlog.outside_window', 1);

        $this->assertGreaterThanOrEqual(29, $response->json('backlog.oldest_age_days'));
    }

    public function testReportBuildsADayTimeline(): void
    {
        $this->seedJournal();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/report?group_by=day');

        $response->assertStatus(200);

        $timeline = collect($response->json('timeline'))->keyBy('period');

        $this->assertCount(2, $timeline);

        $oldest = $timeline->first();
        $this->assertSame(1, $oldest['records']);
        $this->assertSame(0, $oldest['fiscalized']);
        $this->assertEquals(30.0, $oldest['amount_total']);

        $recent = $timeline->last();
        $this->assertSame(3, $recent['records']);
        $this->assertSame(2, $recent['fiscalized']);
        $this->assertEquals(190.0, $recent['amount_total']);
    }

    public function testReportRespectsTheDateRange(): void
    {
        $this->seedJournal();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/report?date_from=' . now()->subDays(2)->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('totals.records', 3)
            ->assertJsonPath('totals.fiscalized', 2);
    }

    public function testReportRejectsAnUnknownGroupBy(): void
    {
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/report?group_by=week');

        $response->assertStatus(422);
    }

    private function seedJournal(): void
    {
        $this->createLog([
            'kind' => MontenegroFiscalLog::KIND_CREATE,
            'status' => MontenegroFiscalLog::STATUS_SENT,
            'jikr' => '11111111-1111-4111-8111-111111111111',
            'total' => 100.00,
            'created_at' => now()->subDay(),
        ]);

        $this->createLog([
            'kind' => MontenegroFiscalLog::KIND_CREATE,
            'status' => MontenegroFiscalLog::STATUS_FAILED,
            'jikr' => null,
            'total' => 50.00,
            'created_at' => now()->subDay(),
        ]);

        $this->createLog([
            'kind' => MontenegroFiscalLog::KIND_ADVANCE,
            'status' => MontenegroFiscalLog::STATUS_SENT,
            'jikr' => '22222222-2222-4222-8222-222222222222',
            'total' => 40.00,
            'created_at' => now()->subDay(),
        ]);

        $this->createLog([
            'kind' => MontenegroFiscalLog::KIND_CREATE,
            'status' => MontenegroFiscalLog::STATUS_FAILED,
            'jikr' => null,
            'total' => 30.00,
            'created_at' => now()->subDays(30),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createLog(array $attributes = []): MontenegroFiscalLog
    {
        return MontenegroFiscalLog::create(array_merge([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'client_id' => $this->client->id,
            'kind' => MontenegroFiscalLog::KIND_CREATE,
            'status' => MontenegroFiscalLog::STATUS_FAILED,
            'iic' => '0E3CB8457E17AE9A4676E3227AAC86F8',
            'total' => 10.00,
            'request_state' => [
                'inv_num' => '5/2026/nn825pg646',
                'ordinal' => 5,
                'tin' => '12345678',
                'tcr' => 'nn825pg646',
            ],
        ], $attributes));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ];
    }
}
