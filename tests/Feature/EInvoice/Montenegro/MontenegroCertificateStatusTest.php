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

use App\Services\EDocument\Standards\Montenegro\Certificate;
use App\Utils\SystemHealth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Covers certificate expiry monitoring, both through the company status endpoint
 * and through the global SystemHealth probe.
 */
class MontenegroCertificateStatusTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testStatusReportsACertificateExpiringSoon(): void
    {
        $this->enableMontenegroWithCertificate(10);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/status');

        $response->assertStatus(200)
            ->assertJsonPath('certificate_expired', false)
            ->assertJsonPath('certificate_expiring_soon', true)
            ->assertJsonPath('certificate_valid', true);

        $days = $response->json('certificate_days_remaining');
        $this->assertIsInt($days);
        $this->assertGreaterThanOrEqual(8, $days);
        $this->assertLessThanOrEqual(10, $days);
        $this->assertSame(Certificate::WARNING_DAYS, $response->json('certificate_warning_days'));
        $this->assertIsString($response->json('certificate_expires_at'));
    }

    public function testStatusReportsAnExpiredCertificate(): void
    {
        $this->enableMontenegroWithCertificate(0);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/einvoice/montenegro/status');

        $response->assertStatus(200)
            ->assertJsonPath('certificate_expired', true)
            ->assertJsonPath('certificate_expiring_soon', false)
            ->assertJsonPath('certificate_valid', false);

        $this->assertLessThanOrEqual(0, $response->json('certificate_days_remaining'));
    }

    public function testCompanySaveDoesNotClearTheCertificatePassphrase(): void
    {
        $this->enableMontenegroWithCertificate(365);

        $this->company->e_invoice_certificate_passphrase = 'secret';
        $this->company->save();
        $this->company = $this->company->fresh();

        $response = $this->withHeaders($this->headers())
            ->putJson('/api/v1/companies/' . $this->company->hashed_id, [
                'e_invoice_certificate_passphrase' => null,
            ]);

        $response->assertStatus(200);

        $this->assertSame('secret', $this->company->fresh()->e_invoice_certificate_passphrase);
    }

    public function testSystemHealthFlagsAnExpiredCertificate(): void
    {
        $this->enableMontenegroWithCertificate(0);

        $summary = SystemHealth::checkMontenegroCertificates();

        $this->assertFalse($summary['is_okay']);
        $this->assertGreaterThanOrEqual(1, $summary['expired']);
        $this->assertContains($this->company->id, array_column($summary['alerts'], 'company_id'));
    }

    public function testSystemHealthIsOkayForAValidCertificate(): void
    {
        $this->enableMontenegroWithCertificate(365);

        $summary = SystemHealth::checkMontenegroCertificates();

        // The probe scans every Montenegro company, so assert against this
        // company rather than against global counts, which other fixtures may
        // influence.
        $this->assertNotContains(
            $this->company->id,
            array_column($summary['alerts'], 'company_id'),
        );
        $this->assertGreaterThanOrEqual(1, $summary['certificates']);
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

    private function enableMontenegroWithCertificate(int $days): void
    {
        $settings = $this->company->settings;
        $settings->e_invoice_type = 'MONTENEGRO';
        $settings->montenegro_tin = '12345678';
        $settings->montenegro_tcr_code = 'nn825pg646';
        $settings->montenegro_business_unit_code = 'ro861ij638';
        $settings->montenegro_operator_code = 'ap514jz098';
        $settings->montenegro_software_code = 'gz434bv927';
        $settings->montenegro_test_mode = true;
        $this->company->settings = $settings;
        $this->company->e_invoice_certificate = base64_encode($this->certificateBundle($days));
        $this->company->save();

        $this->company = $this->company->fresh();
    }

    private function certificateBundle(int $days): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(
            ['commonName' => 'Montenegro Status Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, $days, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return $keyPem . "\n" . $certPem;
    }
}
