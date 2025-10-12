<?php

namespace ESN\CardDAV;

use ESN\Utils\Utils as Utils;

#[\AllowDynamicProperties]
class AddressBookRoot extends \Sabre\DAV\Collection {

    const PRINCIPAL_SUPPORTED_SET = [
        'principals/users',
        'principals/domains'
    ];

    protected $principalBackend;
    protected $addrbookBackend;

    function __construct(\Sabre\DAVACL\PrincipalBackend\BackendInterface $principalBackend,\Sabre\CardDAV\Backend\BackendInterface $addrbookBackend) {
        $this->principalBackend = $principalBackend;
        $this->addrbookBackend = $addrbookBackend;
    }

    public function getName() {
        return \Sabre\CardDAV\Plugin::ADDRESSBOOK_ROOT;
    }

    public function getChildren() {
        $homes = [];

        foreach(self::PRINCIPAL_SUPPORTED_SET as $principalType) {
            $res = $this->principalBackend->getPrincipalsByPrefix($principalType);

            foreach ($res as $principal) {
                $homes[] = $this->initializeChildInstance($principal);
            }
        }

        return $homes;
    }

    public function getChild($name) {
        foreach(self::PRINCIPAL_SUPPORTED_SET as $principalType) {
            $uri = $principalType . '/' . $name;

            try {
                $principal = $this->principalBackend->getPrincipalByPath($uri);
            } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
                return null;
            }

            if ($principal) {
                return $this->initializeChildInstance($principal);
            }
        }

        throw new \Sabre\DAV\Exception\NotFound('Principal with name ' . $name . ' not found');
    }

    private function initializeChildInstance($principal) {
        if (Utils::isUserPrincipal($principal['uri'])) {
            return new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $principal);
        }

        return new \ESN\CardDAV\GroupAddressBookHome($this->addrbookBackend, $principal);
    }
}
