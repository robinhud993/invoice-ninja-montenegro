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

namespace App\Services\EDocument\Standards\Montenegro;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Certificate
 *
 * Resolves the taxpayer signing material used for EFI message and IKOF signing.
 *
 * Montenegro certificates are typically delivered as a PKCS#12 (.p12) keystore
 * that contains the private key and the X.509 certificate. Invoice Ninja stores
 * the e-invoice material in the encrypted company columns
 * e_invoice_certificate / e_invoice_certificate_passphrase, so this helper
 * normalises both PKCS#12 and PEM material into a private key PEM and a
 * certificate PEM.
 *
 * The helper also exposes the X.509 validity window so an expiring signing
 * certificate can be surfaced before it starts rejecting fiscalization requests.
 */
class Certificate
{
    /**
     * Number of days before expiry at which a certificate is reported as
     * expiring soon.
     */
    public const WARNING_DAYS = 30;

    private ?array $parsedCertificate = null;

    public function __construct(
        public readonly string $privateKey,
        public readonly string $certificate,
    ) {}

    public function isUsable(): bool
    {
        return strlen($this->privateKey) > 0
            && strlen($this->certificate) > 0
            && openssl_pkey_get_private($this->privateKey) !== false;
    }

    /**
     * Combined usability: the key material can be read and the X.509
     * certificate has not expired yet.
     */
    public function isValid(): bool
    {
        return $this->isUsable() && ! $this->isExpired();
    }

    /**
     * @return array<string, mixed>
     */
    public function parsed(): array
    {
        if ($this->parsedCertificate === null) {
            $parsed = openssl_x509_parse($this->certificate);
            $this->parsedCertificate = is_array($parsed) ? $parsed : [];
        }

        return $this->parsedCertificate;
    }

    /**
     * The certificate notBefore as an immutable timestamp, if it can be read.
     */
    public function validFrom(): ?CarbonImmutable
    {
        $timestamp = $this->parsed()['validFrom_time_t'] ?? null;

        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }

    /**
     * The certificate notAfter as an immutable timestamp, if it can be read.
     */
    public function validTo(): ?CarbonImmutable
    {
        $timestamp = $this->parsed()['validTo_time_t'] ?? null;

        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }

    public function expiresAt(): ?int
    {
        $timestamp = $this->parsed()['validTo_time_t'] ?? null;

        return is_numeric($timestamp) ? (int) $timestamp : null;
    }

    /**
     * Whole days until expiry. Negative when the certificate has expired, null
     * when the validity window cannot be read.
     */
    public function daysUntilExpiry(): ?int
    {
        $expiresAt = $this->expiresAt();

        if ($expiresAt === null) {
            return null;
        }

        return (int) floor(($expiresAt - now()->getTimestamp()) / 86400);
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && $expiresAt <= now()->getTimestamp();
    }

    /**
     * True when the certificate is still valid but will expire within the given
     * number of days. Already expired certificates are not "expiring soon".
     */
    public function expiresWithin(int $days = self::WARNING_DAYS): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        $remaining = $this->daysUntilExpiry();

        return $remaining !== null && $remaining <= $days;
    }

    /**
     * The certificate subject common name (CN), e.g. the taxpayer name.
     */
    public function commonName(): ?string
    {
        return $this->distinguishedName('subject', ['CN', 'commonName']);
    }

    /**
     * The issuer common name (CN) of the signing certificate.
     */
    public function issuerName(): ?string
    {
        return $this->distinguishedName('issuer', ['CN', 'commonName']);
    }

    /**
     * The taxpayer tax number carried by the certificate: the 8 digit PIB or
     * the 13 digit JMB. Montenegrin registration certificates carry it in the
     * serialNumber attribute (OID 2.5.4.5) or a custom subject OID.
     */
    public function taxNumber(): ?string
    {
        $subject = $this->parsed()['subject'] ?? null;

        if (! is_array($subject)) {
            return null;
        }

        // Prefer the standard serialNumber attribute.
        foreach (['serialNumber', 'SN', '2.5.4.5'] as $key) {
            $value = $subject[$key] ?? null;

            if (is_string($value) && ($digits = self::digits($value)) !== null) {
                return $digits;
            }
        }

        // Fall back to scanning every subject value for an 8 or 13 digit run.
        foreach ($subject as $value) {
            foreach ((array) $value as $entry) {
                if (! is_string($entry) && ! is_numeric($entry)) {
                    continue;
                }

                if (($digits = self::digits((string) $entry)) !== null) {
                    return $digits;
                }
            }
        }

        return null;
    }

    private static function digits(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value);

        if ($digits === null || $digits === '') {
            return null;
        }

        return in_array(strlen($digits), [8, 13], true) ? $digits : null;
    }

    /**
     * Serialisable certificate health summary. Never exposes the private key or
     * the raw certificate body.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'usable' => $this->isUsable(),
            'valid' => $this->isValid(),
            'expired' => $this->isExpired(),
            'expiring_soon' => $this->expiresWithin(),
            'warning_threshold_days' => self::WARNING_DAYS,
            'expires_at' => $this->validTo()?->toIso8601String(),
            'days_remaining' => $this->daysUntilExpiry(),
            'subject' => $this->commonName(),
            'issuer' => $this->issuerName(),
            'tin' => $this->taxNumber(),
        ];
    }

    /**
     * @param array<int, string> $keys
     */
    private function distinguishedName(string $section, array $keys): ?string
    {
        $entry = $this->parsed()[$section] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $entry[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function fromCompany(Company $company): ?self
    {
        $blob = $company->getInvoiceCert();

        if (!is_string($blob) || strlen($blob) === 0) {
            return null;
        }

        $passphrase = $company->getSslPassPhrase();

        return self::fromBlob($blob, is_string($passphrase) ? $passphrase : null);
    }

    public static function fromBlob(string $blob, ?string $passphrase = null): ?self
    {
        if (str_contains($blob, '-----BEGIN')) {
            return self::fromPem($blob);
        }

        $certificates = [];

        if (openssl_pkcs12_read($blob, $certificates, (string) $passphrase)) {
            return self::fromPkcs12Read($certificates);
        }

        $decoded = base64_decode($blob, true);

        if ($decoded !== false && strlen($decoded) > 0 && openssl_pkcs12_read($decoded, $certificates, (string) $passphrase)) {
            return self::fromPkcs12Read($certificates);
        }

        return null;
    }

    /**
     * @param array<string, string> $certificates
     */
    private static function fromPkcs12Read(array $certificates): ?self
    {
        $privateKey = $certificates['pkey'] ?? null;
        $certificate = $certificates['cert'] ?? null;

        if (!is_string($privateKey) || !is_string($certificate) || $privateKey === '' || $certificate === '') {
            return null;
        }

        return new self($privateKey, $certificate);
    }

    private static function fromPem(string $blob): ?self
    {
        $key = [];
        $cert = [];

        if (!preg_match('/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----.*?-----END (?:RSA |EC )?PRIVATE KEY-----/s', $blob, $key)) {
            return null;
        }

        if (!preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $blob, $cert)) {
            return null;
        }

        return new self($key[0], $cert[0]);
    }
}
