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

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\Montenegro;

/**
 * Covers the optional EFI invoice elements: SupplyDateOrPeriod, Fees and the
 * simplified invoice flag.
 */
class MontenegroInvoiceElementsTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testSupplyDateOrPeriodIsEmittedFromTheInvoiceCustomFields(): void
    {
        $this->enableMontenegro();

        $invoice = $this->invoice->fresh();
        $invoice->custom_value3 = '2026-01-01';
        $invoice->custom_value4 = '2026-01-31';
        $invoice->saveQuietly();

        $xml = $this->build($invoice);

        $this->assertStringContainsString('SupplyDateOrPeriod', $xml);
        $this->assertStringContainsString('Start="2026-01-01"', $xml);
        $this->assertStringContainsString('End="2026-01-31"', $xml);
    }

    public function testFeeLineIsReportedAsAFeeAndNotAsAnItem(): void
    {
        $this->enableMontenegro();

        $invoice = $this->invoice->fresh();
        $itemCount = count($invoice->line_items);

        $items = $invoice->line_items;
        $items[0]->custom_value3 = 'PACK';
        $items[0]->cost = 5;
        $items[0]->quantity = 1;
        $items[0]->discount = 0;
        $items[0]->tax_rate1 = 0;
        $items[0]->tax_rate2 = 0;
        $items[0]->tax_rate3 = 0;
        $items[0]->line_total = 5;
        $items[0]->gross_line_total = 5;
        $items[0]->tax_amount = 0;
        $invoice->line_items = $items;
        $invoice->saveQuietly();

        $xml = $this->build($invoice);

        $this->assertStringContainsString('<Fees>', $xml);
        $this->assertStringContainsString('Type="PACK"', $xml);
        $this->assertStringContainsString('Amt="5.00"', $xml);
        $this->assertSame($itemCount - 1, substr_count($xml, '<I '));
    }

    public function testSimplifiedInvoiceFlagIsNoLongerSent(): void
    {
        // Technical specification v5 removed IsSimplifiedInv on 1.10.2021.
        $this->enableMontenegro(simplified: true);

        $xml = $this->build($this->invoice->fresh());

        $this->assertStringNotContainsString('IsSimplifiedInv', $xml);
    }

    public function testHeaderAndPayMethodExtrasAreEmitted(): void
    {
        $this->enableMontenegro();

        $settings = $this->company->settings;
        $settings->montenegro_bank_account = 'ME25505000012345678951';
        $settings->montenegro_comp_card = '4111111111111111';
        $settings->montenegro_self_iss_type = 'SELF';
        $this->company->settings = $settings;
        $this->company->save();
        $this->company = $this->company->fresh();

        $invoice = $this->invoice->fresh();
        $invoice->public_notes = 'Racun za usluge';
        $invoice->saveQuietly();

        $xml = $this->build($invoice);

        $this->assertStringContainsString('BankAccNum="ME25505000012345678951"', $xml);
        $this->assertStringContainsString('TypeOfSelfIss="SELF"', $xml);
        $this->assertStringContainsString('Note="Racun za usluge"', $xml);
        $this->assertStringContainsString('BankAcc="ME25505000012345678951"', $xml);
    }

    public function testSelfIssuingIsSkippedWhenTheBuyerIsIncomplete(): void
    {
        $this->enableMontenegro();

        $settings = $this->company->settings;
        $settings->montenegro_self_iss_type = 'SELF';
        $this->company->settings = $settings;
        $this->company->save();
        $this->company = $this->company->fresh();

        $client = $this->client;
        $client->address1 = '';
        $client->city = '';
        $client->saveQuietly();

        $xml = $this->build($this->invoice->fresh());

        // Declaring self-issuing with an incomplete buyer is rejected by the CIS
        // with error 11, so the attribute must be omitted.
        $this->assertStringNotContainsString('TypeOfSelfIss', $xml);
    }

    public function testVatStatusIsDrivenOnlyByTheExplicitSetting(): void
    {
        $this->enableMontenegro();

        $settings = $this->company->settings;
        $settings->vat_number = '1234567890';
        $settings->montenegro_is_in_vat = false;
        $this->company->settings = $settings;
        $this->company->save();
        $this->company = $this->company->fresh();

        $fiscal = new \App\Services\EDocument\Standards\Montenegro\FiscalSettings($this->company);
        $this->assertFalse($fiscal->isInVat());

        $settings = $this->company->settings;
        $settings->montenegro_is_in_vat = true;
        $this->company->settings = $settings;
        $this->company->save();
        $this->company = $this->company->fresh();

        $fiscal = new \App\Services\EDocument\Standards\Montenegro\FiscalSettings($this->company);
        $this->assertTrue($fiscal->isInVat());
    }

    public function testNonVatIssuerIsReportedTaxFree(): void
    {
        $this->enableMontenegro();

        $settings = $this->company->settings;
        $settings->montenegro_is_in_vat = false;
        $this->company->settings = $settings;
        $this->company->save();
        $this->company = $this->company->fresh();

        $xml = $this->build($this->invoice->fresh());

        $this->assertStringContainsString('IsIssuerInVAT="false"', $xml);
        // The CIS forbids TotVATAmt for a taxpayer that is not in the VAT system.
        $this->assertStringNotContainsString('TotVATAmt', $xml);
        $this->assertStringContainsString('TaxFreeAmt=', $xml);
        $this->assertStringNotContainsString('SameTaxes', $xml);
        $this->assertStringNotContainsString('VR="', $xml);
        $this->assertStringNotContainsString('VA="', $xml);
    }

    public function testNonVatIssuerCannotReportVat(): void
    {
        $this->enableMontenegro();

        $settings = $this->company->settings;
        $settings->montenegro_is_in_vat = false;
        $this->company->settings = $settings;
        $this->company->save();
        $this->company = $this->company->fresh();

        $invoice = $this->invoice->fresh();
        $items = $invoice->line_items;
        $items[0]->tax_rate1 = 21;
        $items[0]->tax_amount = 2.10;
        $invoice->line_items = $items;
        $invoice->saveQuietly();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in the VAT system');

        $this->build($invoice->fresh());
    }

    private function build(\App\Models\Invoice $invoice): string
    {
        $montenegro = new Montenegro($invoice->setRelation('company', $this->company));
        $montenegro->run();

        return (string) $montenegro->getEnvelope();
    }

    private function enableMontenegro(bool $simplified = false): void
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
        $settings->montenegro_simplified_invoice = $simplified;
        $this->company->settings = $settings;
        $this->company->e_invoice_certificate = base64_encode($this->pemBundle());
        $this->company->save();

        $this->company = $this->company->fresh();

        $client = $this->client;
        $client->vat_number = '12345678';
        $client->saveQuietly();
    }

    private function pemBundle(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(
            ['commonName' => 'Montenegro Elements Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return $keyPem . "\n" . $certPem;
    }
}
