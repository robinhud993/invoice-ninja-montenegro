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

use RuntimeException;

/**
 * Ikof
 *
 * Generates the Montenegro IKOF (IIC) and its signature (IICSignature).
 *
 * Algorithm defined in the Fiscalization service technical specification, section 4.3.2:
 *   1. Concatenate TIN, IssueDateTime, InvNum, BusinUnitCode, TCRCode, SoftCode and TotPrice
 *      with the pipe character (UTF-8, decimal code 124).
 *   2. Sign the concatenated string with SHA-256 / RSA (RSASSA-PKCS1-v1_5) using the
 *      taxpayer private key. The resulting signature is hex encoded, 512 characters.
 *   3. The IKOF (IIC) is the MD5 hash of the raw signature bytes, hex encoded, 32 characters.
 */
class Ikof
{
    public function __construct(
        public readonly string $iic,
        public readonly string $signature,
        public readonly string $payload,
    ) {}

    public static function concatenate(
        string $tin,
        string $issueDateTime,
        string $invoiceNumber,
        string $businessUnitCode,
        string $tcrCode,
        string $softwareCode,
        string $totalPrice,
    ): string {
        return implode('|', [
            $tin,
            $issueDateTime,
            $invoiceNumber,
            $businessUnitCode,
            $tcrCode,
            $softwareCode,
            $totalPrice,
        ]);
    }

    /**
     * @throws RuntimeException when the private key cannot be loaded or signing fails.
     */
    public static function generate(string $payload, string $privateKeyPem): self
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new RuntimeException('Montenegro fiscalization: unable to load the signing private key.');
        }

        $rawSignature = '';

        if (!openssl_sign($payload, $rawSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Montenegro fiscalization: unable to sign the IKOF payload.');
        }

        return new self(
            iic: strtoupper(bin2hex(md5($rawSignature, true))),
            signature: strtoupper(bin2hex($rawSignature)),
            payload: $payload,
        );
    }

    /**
     * Recomputes the IKOF from a stored payload. Used when a message is resent with a
     * different (renewed) certificate: the IIC must remain identical, only the message
     * signature may be recalculated. See technical specification section 4.3.
     */
    public function verify(string $privateKeyPem): bool
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            return false;
        }

        $rawSignature = hex2bin($this->signature);

        if ($rawSignature === false) {
            return false;
        }

        return strtoupper(bin2hex(md5($rawSignature, true))) === $this->iic;
    }
}
