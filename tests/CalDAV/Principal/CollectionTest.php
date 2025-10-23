<?php

namespace ESN\CalDAV\Principal;

use \Sabre\DAVACL;

class CollectionTest extends \PHPUnit\Framework\TestCase {

    function testGetChildForPrincipal() {

        $back = new DAVACL\PrincipalBackend\Mock();
        $col = new Collection($back);
        $r = $col->getChildForPrincipal([
            'uri' => 'principals/admin',
        ]);
        $this->assertInstanceOf('ESN\\CalDAV\\Principal\\PrincipalUser', $r);

    }

}
