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

namespace App\Services\EDocument\Standards\Montenegro\Xml;

use DOMDocument;
use DOMElement;
use App\Services\EDocument\Standards\Montenegro\Enums\TcrType;
use App\Services\EDocument\Standards\Montenegro\Enums\CashDepositOperation;
use App\Services\EDocument\Standards\Montenegro\Support\Money;

/**
 * FiscalEventBuilder
 *
 * Builds the two EFI messages that are not invoices:
 *  - RegisterTCRRequest, for registering an electronic fiscal device (ENU)
 *  - RegisterCashDepositRequest, for the daily cash deposit report
 *
 * Both follow the same envelope, signing and transport rules as the invoice
 * request, so the Signer, Envelope and CisClient are reused.
 */
class FiscalEventBuilder
{
    public const NAMESPACE = 'https://efi.tax.gov.me/fs/schema';

    public static function tcr(
        string $requestUuid,
        string $sendDateTime,
        string $issuerTin,
        string $businessUnitCode,
        string $tcrInternalId,
        ?string $softwareCode = null,
        ?string $maintainerCode = null,
        ?string $validFrom = null,
        ?string $validTo = null,
        ?TcrType $type = TcrType::REGULAR,
    ): DOMDocument {
        $doc = self::document();
        $root = $doc->documentElement;

        $root->appendChild(self::header($doc, $requestUuid, $sendDateTime));

        $tcr = self::element($doc, 'TCR');
        $tcr->setAttribute('IssuerTIN', trim($issuerTin));
        $tcr->setAttribute('BusinUnitCode', trim($businessUnitCode));
        $tcr->setAttribute('TCRIntID', $tcrInternalId);

        if ($softwareCode !== null && $softwareCode !== '') {
            $tcr->setAttribute('SoftCode', trim($softwareCode));
        }

        if ($maintainerCode !== null && $maintainerCode !== '') {
            $tcr->setAttribute('MaintainerCode', trim($maintainerCode));
        }

        if ($validFrom !== null && $validFrom !== '') {
            $tcr->setAttribute('ValidFrom', $validFrom);
        }

        if ($validTo !== null && $validTo !== '') {
            $tcr->setAttribute('ValidTo', $validTo);
        }

        if ($type !== null) {
            $tcr->setAttribute('Type', $type->value);
        }

        $root->appendChild($tcr);

        return $doc;
    }

    public static function cashDeposit(
        string $requestUuid,
        string $sendDateTime,
        string $issuerTin,
        string $tcrCode,
        CashDepositOperation $operation,
        float|int|string $cashAmount,
        string $changeDateTime,
    ): DOMDocument {
        $doc = self::document('RegisterCashDepositRequest');
        $root = $doc->documentElement;

        $root->appendChild(self::header($doc, $requestUuid, $sendDateTime));

        $deposit = self::element($doc, 'CashDeposit');
        $deposit->setAttribute('ChangeDateTime', $changeDateTime);
        $deposit->setAttribute('Operation', $operation->value);
        $deposit->setAttribute('CashAmt', Money::decimal($cashAmount));
        $deposit->setAttribute('TCRCode', trim($tcrCode));
        $deposit->setAttribute('IssuerTIN', trim($issuerTin));

        $root->appendChild($deposit);

        return $doc;
    }

    private static function document(string $rootName = 'RegisterTCRRequest'): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;

        $root = $doc->createElementNS(self::NAMESPACE, $rootName);
        $root->setAttribute('Id', 'Request');
        $root->setAttribute('Version', '1');
        $doc->appendChild($root);

        return $doc;
    }

    private static function header(DOMDocument $doc, string $uuid, string $sendDateTime): DOMElement
    {
        $header = self::element($doc, 'Header');
        $header->setAttribute('UUID', $uuid);
        $header->setAttribute('SendDateTime', $sendDateTime);

        return $header;
    }

    private static function element(DOMDocument $doc, string $name): DOMElement
    {
        return $doc->createElementNS(self::NAMESPACE, $name);
    }
}
