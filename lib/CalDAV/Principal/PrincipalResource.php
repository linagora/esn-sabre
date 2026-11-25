<?php

namespace ESN\CalDAV\Principal;

use Sabre\DAV;
use Sabre\DAVACL;

class PrincipalResource extends DAVACL\Principal {

    /**
     * The principal key of the custom address of a resource, besides its <id>@<domain> one.
     * Not a property name: PROPFIND never serves it.
     */
    const CUSTOM_EMAIL_ADDRESS = 'customEmailAddress';

    /**
     * Every lower-cased mailto: address the resource answers to.
     *
     * @return string[]
     */
    function getCalendarAddresses(): array {
        $addresses = [];
        foreach (['{http://sabredav.org/ns}email-address', self::CUSTOM_EMAIL_ADDRESS] as $key) {
            $email = $this->principalProperties[$key] ?? null;
            if (is_string($email) && $email !== '') {
                $addresses[] = 'mailto:' . strtolower($email);
            }
        }

        return array_values(array_unique($addresses));
    }

    function getACL() {
        $acl = parent::getACL();

        $acl[] = [
            'privilege' => '{DAV:}read',
            'principal' => '{DAV:}authenticated',
            'protected' => true,
        ];

        return $acl;
    }

    /**
     * Returns a list of properties for this principal
     *
     * @param array $requestedProperties
     * @return array
     */
    function getProperties($requestedProperties) {
        $properties = parent::getProperties($requestedProperties);

        // If calendar-user-address-set is requested and we have an email address, provide it
        if (in_array('{urn:ietf:params:xml:ns:caldav}calendar-user-address-set', $requestedProperties)) {
            if (isset($this->principalProperties['{http://sabredav.org/ns}email-address'])) {
                $email = $this->principalProperties['{http://sabredav.org/ns}email-address'];
                if (is_string($email) && !empty($email)) {
                    $properties['{urn:ietf:params:xml:ns:caldav}calendar-user-address-set'] =
                        new DAV\Xml\Property\Href(['mailto:' . $email]);
                }
            }
        }

        return $properties;
    }
}
