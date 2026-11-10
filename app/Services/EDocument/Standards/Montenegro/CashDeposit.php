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

use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use App\Services\EDocument\Standards\Montenegro\Enums\CashDepositOperation;
use App\Services\EDocument\Standards\Montenegro\Soap\CisClient;
use App\Services\EDocument\Standards\Montenegro\Soap\Envelope;
use App\Services\EDocument\Standards\Montenegro\Soap\ResponseProcessor;
use App\Services\EDocument\Standards\Montenegro\Xml\FiscalEventBuilder;
use App\Services\EDocument\Standards\Montenegro\Xml\Signer;

/**
 * CashDeposit
 *
 * Reports a cash register deposit or withdrawal to the CIS using the
 * registerCashDeposit operation. On success the CIS returns the FCDC, the
 * fiscalization cash deposit code.
 */
class CashDeposit
{
    public function __construct(private FiscalSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function submit(
        string $tcrCode,
        CashDepositOperation $operation,
        float|int|string $cashAmount,
        ?string $changeDateTime = null,
    ): array {
        $certificate = $this->settings->certificate();

        if ($certificate === null) {
            throw new RuntimeException('Montenegro EFI: no usable signing certificate was found.');
        }

        if (trim($this->settings->tin()) === '') {
            throw new RuntimeException('Montenegro EFI: the issuer TIN is not configured.');
        }

        $sendDateTime = $this->now();

        $change = ($changeDateTime !== null && $changeDateTime !== '')
            ? Carbon::parse($changeDateTime)->format('Y-m-d\TH:i:sP')
            : $sendDateTime;

        $document = FiscalEventBuilder::cashDeposit(
            requestUuid: (string) Str::uuid(),
            sendDateTime: $sendDateTime,
            issuerTin: $this->settings->tin(),
            tcrCode: $tcrCode,
            operation: $operation,
            cashAmount: $cashAmount,
            changeDateTime: $change,
        );

        [$envelope, $requestElement] = Envelope::wrap($document);

        (new Signer($certificate->privateKey, $certificate->certificate))->sign($envelope, $requestElement);

        $client = new CisClient($this->settings->endpoint(), CisClient::SOAP_ACTION_REGISTER_CASH_DEPOSIT);

        $raw = $client->send($envelope->saveXML() ?: '');

        return (new ResponseProcessor())->process($raw['body'], $raw['status'], 'FCDC');
    }

    private function now(): string
    {
        return now($this->settings->company->timezone()?->name ?? 'UTC')->format('Y-m-d\TH:i:sP');
    }
}
