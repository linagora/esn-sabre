<?php

namespace ESN\CalDAV;
require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

class PluginTest extends \PHPUnit_Framework_TestCase {

    function  testGetCalendarHomeForPrincipal() {
        $plugin = new Plugin();

        $this->assertNull($plugin->getCalendarHomeForPrincipal('/principals/123'));
        $this->assertNull($plugin->getCalendarHomeForPrincipal('/users/123'));
        $this->assertNull($plugin->getCalendarHomeForPrincipal('/notprincipal/notuser/123'));
        $this->assertNull($plugin->getCalendarHomeForPrincipal('/principals/users/1/123'));
        $this->assertEquals($plugin->getCalendarHomeForPrincipal('/principals/users/123'), $plugin::CALENDAR_ROOT . '/123');
    }
}