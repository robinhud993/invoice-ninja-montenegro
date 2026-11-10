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
use DOMElement;

/**
 * Envelope
 *
 * Wraps the fiscalization request document in a SOAP 1.1 envelope as required by
 * the technical specification section 3.3. The SOAP header stays empty.
 */
class Envelope
{
    public const NS = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * Imports the request document element into a fresh SOAP envelope and
     * returns the envelope document together with the imported request element,
     * which is the element that must be signed.
     *
     * @return array{0: DOMDocument, 1: DOMElement}
     */
    public static function wrap(DOMDocument $request): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;

        $envelope = $doc->createElementNS(self::NS, 'SOAP-ENV:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:SOAP-ENV', self::NS);
        $doc->appendChild($envelope);

        $envelope->appendChild($doc->createElementNS(self::NS, 'SOAP-ENV:Header'));

        $body = $doc->createElementNS(self::NS, 'SOAP-ENV:Body');
        $envelope->appendChild($body);

        /** @var DOMElement $imported */
        $imported = $doc->importNode($request->documentElement, true);
        $body->appendChild($imported);

        return [$doc, $imported];
    }
}
