<?php

namespace ESN\CardDAV;

class AddressBookRoot extends \Sabre\DAV\Collection {

    const PRINCIPAL_SUPPORTED_SET = [
        'principals/users',
        // 'principals/communities', // Uncomment to reactive the fetch for communities
        'principals/projects',
        'principals/domains'
    ];

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
                $homes[] = new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $principal);
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
                return new \ESN\CardDAV\AddressBookHome(
                    $this->addrbookBackend,
                    $principal
                );
            }
        }

        throw new \Sabre\DAV\Exception\NotFound('Principal with name ' . $name . ' not found');
    }
}
