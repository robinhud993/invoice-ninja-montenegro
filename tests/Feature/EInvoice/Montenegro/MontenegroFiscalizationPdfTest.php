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
use App\Models\InvoiceInvitation;
use App\Utils\HtmlEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Verifies that the Montenegro EFI fiscalization data is rendered on the invoice
 * PDF through the $montenegro_fiscalization design variable.
 */
class MontenegroFiscalizationPdfTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testFiscalizationBlockIsRenderedForAFiscalizedInvoice(): void
    {
        $this->enableMontenegro();

        $this->invoice->backup->guid = 'e93c317c-76ca-4e5d-a1eb-850a79f2e007';
        $this->invoice->saveQuietly();

        MontenegroFiscalLog::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'client_id' => $this->client->id,
            'kind' => MontenegroFiscalLog::KIND_CREATE,
            'status' => MontenegroFiscalLog::STATUS_SENT,
            'iic' => '0E3CB8457E17AE9A4676E3227AAC86F8',
            'jikr' => 'e93c317c-76ca-4e5d-a1eb-850a79f2e007',
            'total' => 199.00,
            'sent_at' => now(),
            'request_state' => [
                'inv_num' => 'ro861ij638/118/2026/nn825pg646',
                'ordinal' => 118,
                'mode' => 'PROD',
                'test_mode' => false,
                'tcr' => 'nn825pg646',
                'operator' => 'ap514jz098',
                'tin' => '12345678',
                'total' => 199.00,
                'issue_date_time' => now()->format('Y-m-d\TH:i:sP'),
            ],
        ]);

        $block = $this->renderHtmlEngineValues()['$montenegro_fiscalization']['value'];

        $this->assertStringContainsString('IKOF', $block);
        $this->assertStringContainsString('0E3CB8457E17AE9A4676E3227AAC86F8', $block);
        $this->assertStringContainsString('JIKR', $block);
        $this->assertStringContainsString('e93c317c-76ca-4e5d-a1eb-850a79f2e007', $block);
        $this->assertStringContainsString('Broj računa', $block);
        $this->assertStringContainsString('ro861ij638/118/2026/nn825pg646', $block);
        $this->assertStringContainsString('Redni br. računa', $block);
        $this->assertStringContainsString('118', $block);
        $this->assertStringContainsString('Mode', $block);
        $this->assertStringContainsString('PROD', $block);
        $this->assertStringContainsString('Vrijeme fisk.', $block);
        $this->assertStringContainsString('Uređaj', $block);
        $this->assertStringContainsString('nn825pg646', $block);
        $this->assertStringContainsString('Operater', $block);
        $this->assertStringContainsString('ap514jz098', $block);
        $this->assertStringContainsString('data:image/png;base64,', $block);
    }

    public function testFiscalizationBlockIsEmptyForANonFiscalizedInvoice(): void
    {
        $this->enableMontenegro();

        $values = $this->renderHtmlEngineValues();

        $this->assertSame('', $values['$montenegro_fiscalization']['value']);
        $this->assertSame('', $values['$montenegro_ikof']['value']);
        $this->assertSame('', $values['$montenegro_jikr']['value']);
    }

    public function testIndividualFiscalFieldsAreAvailable(): void
    {
        $this->enableMontenegro();

        $this->invoice->backup->guid = 'abc';
        $this->invoice->saveQuietly();

        MontenegroFiscalLog::create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'client_id' => $this->client->id,
            'kind' => MontenegroFiscalLog::KIND_ADVANCE,
            'status' => MontenegroFiscalLog::STATUS_SENT,
            'iic' => 'ABC123',
            'jikr' => 'abc',
            'total' => 10.00,
            'sent_at' => now(),
            'request_state' => [
                'inv_num' => '5/2026/nn825pg646',
                'ordinal' => 5,
                'mode' => 'TEST',
                'tcr' => 'nn825pg646',
                'operator' => 'ap514jz098',
            ],
        ]);

        $values = $this->renderHtmlEngineValues();

        $this->assertSame('ABC123', $values['$montenegro_ikof']['value']);
        $this->assertSame('abc', $values['$montenegro_jikr']['value']);
        $this->assertSame('5/2026/nn825pg646', $values['$montenegro_invoice_number']['value']);
        $this->assertSame('5', $values['$montenegro_ordinal']['value']);
        $this->assertSame('TEST', $values['$montenegro_mode']['value']);
        $this->assertSame('nn825pg646', $values['$montenegro_tcr_code']['value']);
        $this->assertSame('ap514jz098', $values['$montenegro_operator_code']['value']);
    }

    public function testStandardDesignsIncludeTheMontenegroPlaceholder(): void
    {
        foreach (['bold', 'business', 'calm', 'clean', 'creative', 'elegant', 'hipster', 'modern', 'plain', 'playful', 'tech'] as $design) {
            $path = resource_path('views/pdf-designs/' . $design . '.html');

            $this->assertFileExists($path);
            $this->assertStringContainsString('$montenegro_fiscalization', file_get_contents($path), "Design {$design} is missing the placeholder");
        }
    }

    public function testProvisionalNoteIsShownForAnUnconfirmedInvoice(): void
    {
        $this->enableMontenegro();

        MontenegroFiscalLog::create([
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
                'mode' => 'TEST',
                'tcr' => 'nn825pg646',
                'operator' => 'ap514jz098',
            ],
        ]);

        $this->invoice->backup->guid = '';
        $this->invoice->saveQuietly();

        $block = $this->renderHtmlEngineValues()['$montenegro_fiscalization']['value'];

        $this->assertStringContainsString('IKOF', $block);
        $this->assertStringContainsString('0E3CB8457E17AE9A4676E3227AAC86F8', $block);
        $this->assertStringContainsString('naknadno', $block);
        $this->assertStringNotContainsString('data:image/png;base64,', $block);
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
        $settings->montenegro_test_mode = false;
        $this->company->settings = $settings;
        $this->company->save();

        $this->company->unsetRelation('settings');
    }

    /**
     * @return array<string, array{value: string, label: string}>
     */
    private function renderHtmlEngineValues(): array
    {
        $invitation = InvoiceInvitation::factory()
            ->for($this->invoice)
            ->for($this->client->contacts->first(), 'contact')
            ->for($this->company)
            ->for($this->user)
            ->make();

        return (new HtmlEngine($invitation))->buildEntityDataArray();
    }
}
