<?php

namespace OneLogin\Saml;

use DOMDocument;
use Exception;

use Shareworks\XmlSecurity\Signature;
use Shareworks\XmlSecurity\Encoder;

/**
 * Determine if the SAML response is valid using a provided x509 certificate.
 */
class XmlSec
{
    /**
     * A SamlResponse class provided to the constructor.
     * @var Settings
     */
    protected $_settings;

    /**
     * The document to be tested.
     * @var \DomDocument
     */
    protected $_document;

    /**
     * Construct the SamlXmlSec object.
     *
     * @param Settings $settings A SamlResponse settings object containing the necessary
     *                                          x509 certicate to test the document.
     * @param Response $response The document to test.
     */
    public function __construct(Settings $settings, Response $response)
    {
        $this->_settings = $settings;
        $this->_document = clone $response->document;
    }

    /**
     * Verify that the document only contains a single Assertion
     *
     * @return bool TRUE if the document passes.
     */
    public function validateNumAssertions()
    {
        $rootNode = $this->_document;
        $assertionNodes = $rootNode->getElementsByTagName('Assertion');
        return ($assertionNodes->length == 1);
    }

    /**
     * Verify that the document is still valid according
     *
     * @return bool
     */
    public function validateTimestamps()
    {
        $rootNode = $this->_document;
        $timestampNodes = $rootNode->getElementsByTagName('Conditions');
        for ($i = 0; $i < $timestampNodes->length; $i++) {
            $nbAttribute = $timestampNodes->item($i)->attributes->getNamedItem("NotBefore");
            $naAttribute = $timestampNodes->item($i)->attributes->getNamedItem("NotOnOrAfter");
            if ($nbAttribute && strtotime($nbAttribute->textContent) > time()) {
                return false;
            }
            if ($naAttribute && strtotime($naAttribute->textContent) <= time()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function isValid()
    {
        $objXMLSecDSig = new Signature();

        $objDSig = $objXMLSecDSig->locateSignature($this->_document);
        if (!$objDSig) {
            throw new Exception('Cannot locate Signature Node');
        }
        $objXMLSecDSig->canonicalizeSignedInfo();
        $objXMLSecDSig->idKeys = array('ID');

        $retVal = $objXMLSecDSig->validateReference();
        if (!$retVal) {
            throw new Exception('Reference Validation Failed');
        }

        $singleAssertion = $this->validateNumAssertions();
        if (!$singleAssertion) {
            throw new Exception('Multiple assertions are not supported');
        }

        $validTimestamps = $this->validateTimestamps();
        if (!$validTimestamps) {
            throw new Exception('Timing issues (please check your clock settings)
            ');
        }

        $objKey = $objXMLSecDSig->locateKey();
        if (!$objKey) {
            throw new Exception('We have no idea about the key');
        }

        Encoder::staticLocateKeyInfo($objKey, $objDSig);

        $objKey->loadKey($this->_settings->idpPublicCertificate, FALSE, TRUE);

        return ($objXMLSecDSig->verify($objKey) === 1);
    }
}