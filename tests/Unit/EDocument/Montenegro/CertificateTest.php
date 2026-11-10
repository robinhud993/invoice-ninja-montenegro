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

namespace Tests\Unit\EDocument\Montenegro;

use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\Certificate;

class CertificateTest extends TestCase
{
    private string $keyPem = '';

    private string $certPem = '';

    private string $p12 = '';

    protected function setUp(): void
    {
        parent::setUp();

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($resource, 'Unable to generate a test RSA key.');

        $csr = openssl_csr_new(
            ['commonName' => 'Montenegro Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);

        openssl_x509_export($certificate, $certPem);
        openssl_pkey_export($resource, $keyPem);
        openssl_pkcs12_export($certificate, $p12, $resource, 'secret');

        $this->keyPem = $keyPem;
        $this->certPem = $certPem;
        $this->p12 = $p12;
    }

    public function testPemBundleIsParsed(): void
    {
        $certificate = Certificate::fromBlob($this->keyPem . "\n" . $this->certPem);

        $this->assertNotNull($certificate);
        $this->assertTrue($certificate->isUsable());
    }

    public function testPkcs12IsParsedWithTheCorrectPassphrase(): void
    {
        $certificate = Certificate::fromBlob($this->p12, 'secret');

        $this->assertNotNull($certificate);
        $this->assertTrue($certificate->isUsable());
    }

    public function testBase64EncodedPkcs12IsParsed(): void
    {
        $certificate = Certificate::fromBlob(base64_encode($this->p12), 'secret');

        $this->assertNotNull($certificate);
        $this->assertTrue($certificate->isUsable());
    }

    public function testPkcs12WithWrongPassphraseIsRejected(): void
    {
        $this->assertNull(Certificate::fromBlob($this->p12, 'wrong-passphrase'));
    }

    public function testValidCertificateReportsAnExpiryWindow(): void
    {
        $certificate = Certificate::fromBlob($this->keyPem . "\n" . $this->certPem);

        $this->assertNotNull($certificate);
        $this->assertFalse($certificate->isExpired());
        $this->assertTrue($certificate->isValid());
        $this->assertFalse($certificate->expiresWithin(30));
        $this->assertNotNull($certificate->validFrom());
        $this->assertNotNull($certificate->validTo());
        $this->assertGreaterThan(300, $certificate->daysUntilExpiry());

        $status = $certificate->status();
        $this->assertTrue($status['usable']);
        $this->assertTrue($status['valid']);
        $this->assertFalse($status['expired']);
        $this->assertFalse($status['expiring_soon']);
        $this->assertSame(Certificate::WARNING_DAYS, $status['warning_threshold_days']);
        $this->assertSame('Montenegro Test', $status['subject']);
        $this->assertIsString($status['expires_at']);
    }

    public function testCertificateExpiringWithinTheWarningWindowIsFlagged(): void
    {
        [$key, $cert] = $this->issueCertificate(10);

        $certificate = Certificate::fromBlob($key . "\n" . $cert);

        $this->assertNotNull($certificate);
        $this->assertTrue($certificate->isUsable());
        $this->assertFalse($certificate->isExpired());
        $this->assertTrue($certificate->isValid());
        $this->assertTrue($certificate->expiresWithin(30));
        $this->assertFalse($certificate->expiresWithin(5));
        $this->assertLessThanOrEqual(10, $certificate->daysUntilExpiry());

        $status = $certificate->status();
        $this->assertFalse($status['expired']);
        $this->assertTrue($status['expiring_soon']);
        // Still valid: it has not expired yet, so fiscalization can continue.
        $this->assertTrue($status['valid']);
    }

    public function testExpiredCertificateIsDetected(): void
    {
        [$key, $cert] = $this->issueCertificate(0);

        $certificate = Certificate::fromBlob($key . "\n" . $cert);

        $this->assertNotNull($certificate);
        $this->assertTrue($certificate->isUsable());
        $this->assertTrue($certificate->isExpired());
        $this->assertFalse($certificate->isValid());
        $this->assertLessThanOrEqual(0, $certificate->daysUntilExpiry());

        $status = $certificate->status();
        $this->assertTrue($status['expired']);
        $this->assertFalse($status['expiring_soon']);
        $this->assertFalse($status['valid']);
    }

    public function testTaxNumberIsDerivedFromTheCertificateSubject(): void
    {
        [$key, $cert] = $this->issueCertificateWithSubject(365, [
            'commonName' => 'Montenegro Taxpayer',
            'countryName' => 'ME',
            'organizationName' => '12345678',
        ]);

        $certificate = Certificate::fromBlob($key . "\n" . $cert);

        $this->assertNotNull($certificate);
        $this->assertSame('12345678', $certificate->taxNumber());
        $this->assertSame('12345678', $certificate->status()['tin']);
    }

    public function testTaxNumberIsNullWhenTheSubjectCarriesNoTin(): void
    {
        [$key, $cert] = $this->issueCertificateWithSubject(365, [
            'commonName' => 'Montenegro Taxpayer',
            'countryName' => 'ME',
            'organizationName' => 'Invoice Ninja',
        ]);

        $certificate = Certificate::fromBlob($key . "\n" . $cert);

        $this->assertNotNull($certificate);
        $this->assertNull($certificate->taxNumber());
    }

    /**
     * @param array<string, string> $subject
     *
     * @return array{0: string, 1: string}
     */
    private function issueCertificateWithSubject(int $days, array $subject): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new($subject, $resource, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $resource, $days, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return [$keyPem, $certPem];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function issueCertificate(int $days): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(
            ['commonName' => 'Montenegro Test', 'countryName' => 'ME', 'organizationName' => 'Invoice Ninja'],
            $resource,
            ['digest_alg' => 'sha256']
        );

        $certificate = openssl_csr_sign($csr, null, $resource, $days, ['digest_alg' => 'sha256']);

        openssl_pkey_export($resource, $keyPem);
        openssl_x509_export($certificate, $certPem);

        return [$keyPem, $certPem];
    }
}
