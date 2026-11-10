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

use RuntimeException;
use App\Models\MontenegroFiscalLog;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Covers the Montenegro fiscal journal retention guard, the retention command
 * and the fiscal journal export endpoints and CLI.
 */
class MontenegroFiscalLogRetentionTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testRecentFiscalRecordCannotBeDeleted(): void
    {
        $log = $this->createLog();

        $this->expectException(RuntimeException::class);

        $log->delete();
    }

    public function testRecordPastTheRetentionWindowCanBeDeletedThroughTheModel(): void
    {
        Config::set('services.montenegro.fiscal_log_retention_days', 90);

        $log = $this->createLog(['created_at' => now()->subDays(120)]);

        $this->assertFalse($log->withinRetention());
        $this->assertTrue((bool) $log->delete());
    }

    public function testRetentionCommandPurgesRecordsOlderThanTheWindow(): void
    {
        $old = $this->createLog(['created_at' => now()->subDays(120)]);
        $recent = $this->createLog(['created_at' => now()->subDays(10)]);

        $this->artisan('ninja:montenegro-log-retention', ['--days' => 90])->assertExitCode(0);

        $this->assertDatabaseMissing('montenegro_fiscal_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('montenegro_fiscal_logs', ['id' => $recent->id]);
    }

    public function testRetentionDryRunKeepsRecords(): void
    {
        $old = $this->createLog(['created_at' => now()->subDays(120)]);

        $this->artisan('ninja:montenegro-log-retention', ['--days' => 90, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('montenegro_fiscal_logs', ['id' => $old->id]);
    }

    public function testRetentionIsDisabledWhenTheWindowIsZero(): void
    {
        Config::set('services.montenegro.fiscal_log_retention_days', 0);

        $old = $this->createLog(['created_at' => now()->subDays(5000)]);

        $this->artisan('ninja:montenegro-log-retention')->assertExitCode(0);

        $this->assertDatabaseHas('montenegro_fiscal_logs', ['id' => $old->id]);
    }

    public function testFiscalLogListIsPaginated(): void
    {
        $this->createLog(['jikr' => '11111111-1111-4111-8111-111111111111', 'status' => MontenegroFiscalLog::STATUS_SENT]);
        $this->createLog();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/fiscal_logs?per_page=1');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        $this->assertSame('invoice', $response->json('data.0.entity_type'));
        $this->assertIsString($response->json('data.0.hashed_id'));
    }

    public function testFiscalLogListFiltersByStatus(): void
    {
        $this->createLog(['jikr' => '11111111-1111-4111-8111-111111111111', 'status' => MontenegroFiscalLog::STATUS_SENT]);
        $this->createLog(['status' => MontenegroFiscalLog::STATUS_FAILED]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/fiscal_logs?status=failed');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function testFiscalJournalCsvExportContainsTheHeaderAndRows(): void
    {
        $this->createLog(['jikr' => '11111111-1111-4111-8111-111111111111', 'status' => MontenegroFiscalLog::STATUS_SENT]);

        $response = $this->withHeaders($this->headers())
            ->get('/api/v1/einvoice/montenegro/fiscal_logs/export?format=csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('iic', $csv);
        $this->assertStringContainsString('0E3CB8457E17AE9A4676E3227AAC86F8', $csv);
        $this->assertStringContainsString('11111111-1111-4111-8111-111111111111', $csv);
    }

    public function testFiscalJournalJsonExportReturnsRecords(): void
    {
        $this->createLog(['jikr' => '11111111-1111-4111-8111-111111111111', 'status' => MontenegroFiscalLog::STATUS_SENT]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/fiscal_logs/export?format=json');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.jikr', '11111111-1111-4111-8111-111111111111')
            ->assertJsonPath('data.0.invoice_number', '5/2026/nn825pg646');
    }

    public function testExportLogsCommandWritesCsv(): void
    {
        $this->createLog(['jikr' => '11111111-1111-4111-8111-111111111111', 'status' => MontenegroFiscalLog::STATUS_SENT]);

        $path = storage_path('app/test-montenegro-fiscal-journal.csv');

        $this->artisan('ninja:montenegro-export-logs', [
            '--company' => $this->company->hashed_id,
            '--format' => 'csv',
            '--output' => $path,
        ])->assertExitCode(0);

        $this->assertFileExists($path);
        $this->assertStringContainsString('0E3CB8457E17AE9A4676E3227AAC86F8', (string) file_get_contents($path));

        @unlink($path);
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
            'total' => 100.00,
            'request_state' => [
                'inv_num' => '5/2026/nn825pg646',
                'ordinal' => 5,
                'issue_date_time' => now()->format('Y-m-d\TH:i:sP'),
                'tin' => '12345678',
                'tcr' => 'nn825pg646',
                'bu' => 'ro861ij638',
                'operator' => 'ap514jz098',
                'sw' => 'gz434bv927',
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
