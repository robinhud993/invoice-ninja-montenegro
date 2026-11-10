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
use DOMXPath;
use RuntimeException;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Signer
 *
 * Produces the enveloped XML digital signature required by the fiscalization
 * service (technical specification section 4.3.1):
 *
 *  - Reference URI      #Request  (the Id attribute of the request root element)
 *  - Transform          enveloped-signature then exclusive C14N
 *  - Digest method      SHA-256
 *  - Signature method   RSA-SHA256
 *  - KeyInfo            X509Certificate only
 *
 * The signature is appended as the last child of the request element, matching
 * the XML examples in the specification.
 */
class Signer
{
    public function __construct(
        private string $privateKeyPem,
        private string $certificatePem,
    ) {}

    /**
     * Signs the given element in place. The element must carry an Id attribute
     * equal to "Request".
     */
    public function sign(DOMDocument $doc, DOMElement $element): void
    {
        if ($element->getAttribute('Id') === '') {
            throw new RuntimeException('Montenegro fiscalization: the element to sign has no Id attribute.');
        }

        $objDSig = new XMLSecurityDSig();
        $objDSig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

        $objDSig->addReference(
            $element,
            XMLSecurityDSig::SHA256,
            [
                'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
                'http://www.w3.org/2001/10/xml-exc-c14n#',
            ],
            ['id_name' => 'Id', 'overwrite' => false]
        );

        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $objKey->loadKey($this->privateKeyPem, false);

        $objDSig->add509Cert($this->certificatePem, true, false);

        $objDSig->sign($objKey, $element);
    }

    /**
     * Verifies the first signature in the document against the certificate
     * embedded in its KeyInfo. This proves integrity of the response, not that
     * the certificate is trusted. Do not use it as an authentication decision
     * on its own.
     */
    public function verifyEmbedded(DOMDocument $doc): bool
    {
        try {
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);

            $certNodes = $xpath->query('//ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate');

            if ($certNodes === false || $certNodes->length === 0) {
                return false;
            }

            $certificate = (string) $certNodes->item(0)->textContent;

            $verifier = new XMLSecurityDSig();
            $signature = $verifier->locateSignature($doc);

            if ($signature === null || $verifier->canonicalizeSignedInfo() === null) {
                return false;
            }

            if (!$verifier->validateReference()) {
                return false;
            }

            $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
            $key->loadKey($certificate, false, true);

            return $verifier->verify($key) === 1;
        } catch (\Throwable $e) {
            nlog('MONTENEGRO FISCALIZATION ERROR: [verify] ' . $e->getMessage());

            return false;
        }
    }
}
