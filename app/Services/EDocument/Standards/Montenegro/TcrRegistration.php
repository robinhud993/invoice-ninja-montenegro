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

use Illuminate\Support\Str;
use RuntimeException;
use App\Services\EDocument\Standards\Montenegro\Enums\TcrType;
use App\Services\EDocument\Standards\Montenegro\Soap\CisClient;
use App\Services\EDocument\Standards\Montenegro\Soap\Envelope;
use App\Services\EDocument\Standards\Montenegro\Soap\ResponseProcessor;
use App\Services\EDocument\Standards\Montenegro\Xml\FiscalEventBuilder;
use App\Services\EDocument\Standards\Montenegro\Xml\Signer;

/**
 * TcrRegistration
 *
 * Registers an electronic fiscal device (ENU / TCR) with the CIS using the
 * registerTCR operation. On success the CIS returns the TCRCode, which is the
 * ENU code used as the TCRCode attribute on every invoice issued from it.
 */
class TcrRegistration
{
    public function __construct(private FiscalSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function register(
        string $businessUnitCode,
        string $tcrInternalId,
        ?string $softwareCode = null,
        ?string $maintainerCode = null,
        ?string $validFrom = null,
        ?string $validTo = null,
        ?TcrType $type = TcrType::REGULAR,
    ): array {
        $certificate = $this->settings->certificate();

        if ($certificate === null) {
            throw new RuntimeException('Montenegro EFI: no usable signing certificate was found.');
        }

        if (trim($this->settings->tin()) === '') {
            throw new RuntimeException('Montenegro EFI: the issuer TIN is not configured.');
        }

        $document = FiscalEventBuilder::tcr(
            requestUuid: (string) Str::uuid(),
            sendDateTime: $this->now(),
            issuerTin: $this->settings->tin(),
            businessUnitCode: $businessUnitCode,
            tcrInternalId: $tcrInternalId,
            softwareCode: $softwareCode ?? $this->settings->softwareCode(),
            maintainerCode: $maintainerCode ?? $this->settings->maintainerCode(),
            validFrom: $validFrom,
            validTo: $validTo,
            type: $type,
        );

        [$envelope, $requestElement] = Envelope::wrap($document);

        (new Signer($certificate->privateKey, $certificate->certificate))->sign($envelope, $requestElement);

        $client = new CisClient($this->settings->endpoint(), CisClient::SOAP_ACTION_REGISTER_TCR);

        $raw = $client->send($envelope->saveXML() ?: '');

        return (new ResponseProcessor())->process($raw['body'], $raw['status'], 'TCRCode');
    }

    private function now(): string
    {
        return now($this->settings->company->timezone()?->name ?? 'UTC')->format('Y-m-d\TH:i:sP');
    }
}
