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

use DOMDocument;
use DOMXPath;

/**
 * ResponseProcessor
 *
 * Parses a RegisterInvoiceResponse or a SOAP Fault returned by the CIS.
 *
 * A successful response carries the JIKR (FIC element) and the request and
 * response UUIDs. A failure response is a SOAP 1.1 Fault with a numeric error
 * code in the detail element (technical specification section 3.8).
 */
class ResponseProcessor
{
    /**
     * @param string $codeElement the element that carries the CIS generated code,
     *                            FIC for invoices, TCRCode for TCR registration,
     *                            FCDC for a cash deposit.
     *
     * @return array{
     *     success: bool,
     *     jikr: ?string,
     *     code: ?string,
     *     request_uuid: ?string,
     *     response_uuid: ?string,
     *     send_date_time: ?string,
     *     fault: bool,
     *     error_code: ?int,
     *     message: string,
     *     http_status: ?int
     * }
     */
    public function process(?string $soapXml, ?int $httpStatus = null, string $codeElement = 'FIC'): array
    {
        $result = [
            'success' => false,
            'jikr' => null,
            'code' => null,
            'request_uuid' => null,
            'response_uuid' => null,
            'send_date_time' => null,
            'fault' => false,
            'error_code' => null,
            'message' => '',
            'http_status' => $httpStatus,
        ];

        if ($soapXml === null || trim($soapXml) === '') {
            $result['message'] = 'Empty response from the fiscalization service.';

            return $result;
        }

        $doc = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($soapXml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $result['message'] = 'Unable to parse the fiscalization service response.';

            return $result;
        }

        $xpath = new DOMXPath($doc);

        $faultNodes = $xpath->query('//*[local-name()="Fault"]');

        if ($faultNodes !== false && $faultNodes->length > 0) {
            $result['fault'] = true;

            $faultString = $xpath->query('//*[local-name()="Fault"]/*[local-name()="faultstring"]');
            if ($faultString !== false && $faultString->length > 0) {
                $result['message'] = trim((string) $faultString->item(0)->textContent);
            }

            $code = $xpath->query('//*[local-name()="Fault"]//*[local-name()="code"]');
            if ($code !== false && $code->length > 0) {
                $result['error_code'] = (int) trim((string) $code->item(0)->textContent);
            }

            $requestUuid = $xpath->query('//*[local-name()="Fault"]//*[local-name()="requestUUID"]');
            if ($requestUuid !== false && $requestUuid->length > 0) {
                $result['request_uuid'] = trim((string) $requestUuid->item(0)->textContent);
            }

            $responseUuid = $xpath->query('//*[local-name()="Fault"]//*[local-name()="responseUUID"]');
            if ($responseUuid !== false && $responseUuid->length > 0) {
                $result['response_uuid'] = trim((string) $responseUuid->item(0)->textContent);
            }

            return $result;
        }

        $nodes = $xpath->query('//*[local-name()="' . $codeElement . '"]');

        if ($nodes === false || $nodes->length === 0) {
            $result['message'] = 'Response received but no ' . $codeElement . ' element was present.';

            return $result;
        }

        $result['code'] = trim((string) $nodes->item(0)->textContent);
        $result['success'] = $result['code'] !== '';

        if ($codeElement === 'FIC') {
            $result['jikr'] = $result['code'];
        }

        $header = $xpath->query('//*[local-name()="Header"][@RequestUUID]');

        if ($header !== false && $header->length > 0) {
            $headerNode = $header->item(0);

            if ($headerNode instanceof \DOMElement) {
                $result['request_uuid'] = $headerNode->getAttribute('RequestUUID') ?: null;
                $result['response_uuid'] = $headerNode->getAttribute('UUID') ?: null;
                $result['send_date_time'] = $headerNode->getAttribute('SendDateTime') ?: null;
            }
        }

        return $result;
    }

    /**
     * Human readable description for the numeric error codes listed in the
     * technical specification section 3.8.2 and the functional specification.
     */
    public static function describeErrorCode(?int $code): string
    {
        if ($code === null) {
            return '';
        }

        return match ($code) {
            0 => 'Exception while extracting the received XML message during the size check.',
            1 => 'The received XML message exceeds the allowed size.',
            2 => 'Client time differs from server time by more than allowed.',
            10 => 'Exception while validating the received XML message against the XSD.',
            11 => 'The received XML message failed XSD validation.',
            20 => 'Exception while checking the message signature.',
            21 => 'Missing Signature element.',
            22 => 'Missing Request element.',
            23 => 'Exception while extracting the Signature element.',
            24 => 'More than one Signature element provided.',
            25 => 'Signed the wrong XML element.',
            26 => 'Wrong signature method.',
            27 => 'Wrong canonicalization method.',
            28 => 'Wrong digest method.',
            29 => 'Cryptographic signature incorrect.',
            30 => 'Digest calculation incorrect.',
            31 => 'Overall signature incorrect.',
            32 => 'More KeyInfo elements than needed.',
            33 => 'Certificate is not an X509 certificate.',
            34 => 'Certificate is not valid.',
            35 => 'Certificate was not issued by a registered CA.',
            36 => 'Certificate has expired.',
            37 => 'TIN in the XML does not match the TIN in the certificate.',
            38 => 'Certificate status revoked.',
            39 => 'Certificate status unknown.',
            40 => 'Invoice amount too large for a cash invoice.',
            41 => 'Business unit code does not reference an active business unit.',
            42 => 'Software code does not reference active software.',
            43 => 'Maintainer code does not reference an active maintainer.',
            44 => 'Issuer VAT status does not match IsIssuerInVAT.',
            45 => 'Valid from cannot be in the past.',
            46 => 'Valid to cannot be in the past.',
            47 => 'Valid to cannot be before valid from.',
            48 => 'An active ENU cannot be updated.',
            49 => 'Change date and time differs from CIS time by more than allowed.',
            50 => 'Cash amount cannot be negative for an INITIAL operation.',
            51 => 'Cash amount cannot be zero for a WITHDRAW operation.',
            52 => 'Taxpayer does not exist in the Registry of taxpayers.',
            53 => 'ENU code does not reference a registered or active ENU.',
            54 => 'Issuer ID type must be TIN.',
            55 => 'Taxpayer is not active in the Registry of taxpayers.',
            56 => 'A cash deposit with INITIAL operation is already registered for today.',
            57 => 'A deactivated ENU cannot be modified.',
            default => $code >= 900 ? 'Internal server exception in the fiscalization service.' : 'Unknown fiscalization error code.',
        };
    }
}
