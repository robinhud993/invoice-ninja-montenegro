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

namespace App\Services\EDocument\Standards\Montenegro\Soap;

use Illuminate\Support\Facades\Http;

/**
 * CisClient
 *
 * Minimal SOAP 1.1 client for the EFI Central Information System (CIS).
 *
 * The service is one-way TLS: the CIS presents a server certificate and the
 * caller authenticates by signing the request message, so no client TLS
 * certificate is configured here.
 */
class CisClient
{
    public const SOAP_ACTION_REGISTER_INVOICE = 'https://efi.tax.gov.me/fs/RegisterInvoice';
    public const SOAP_ACTION_REGISTER_TCR = 'https://efi.tax.gov.me/fs/RegisterTCR';
    public const SOAP_ACTION_REGISTER_CASH_DEPOSIT = 'https://efi.tax.gov.me/fs/RegisterCashDeposit';

    public function __construct(
        private string $endpoint,
        private string $soapAction = self::SOAP_ACTION_REGISTER_INVOICE,
        private int $timeout = 30,
    ) {}

    /**
     * @return array{status: int, successful: bool, body: string}
     */
    public function send(string $soapXml): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => $this->soapAction,
        ])
            ->withOptions([
                'timeout' => $this->timeout,
                'connect_timeout' => 10,
            ])
            ->withBody($soapXml, 'text/xml')
            ->post($this->endpoint);

        return [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => $response->body(),
        ];
    }
}
