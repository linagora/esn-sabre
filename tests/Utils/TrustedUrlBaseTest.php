<?php

namespace ESN\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedUrlBaseTest extends TestCase {
    static function urlCases() {
        return [
            'same host' => ['https://meet.linagora.com/room', true],
            'case insensitive host' => ['https://MEET.LINAGORA.COM/room', true],
            'host suffix attack' => ['https://meet.linagora.com.evil.test/room', false],
            'userinfo attack' => ['https://meet.linagora.com@evil.test/room', false],
            'wrong scheme' => ['http://meet.linagora.com/room', false],
            'wrong port' => ['https://meet.linagora.com:8443/room', false],
            'relative URL' => ['/room', false],
        ];
    }

    #[DataProvider('urlCases')]
    function testMatchesTheTrustedOrigin($url, $expected) {
        $this->assertSame($expected, (new TrustedUrlBase('https://meet.linagora.com'))->accepts($url));
    }

    function testFdqnPlaceholderMatchesOneDynamicHostLabel() {
        $base = new TrustedUrlBase('https://{fdqn}-drive.linagora.com');

        $this->assertTrue($base->accepts('https://tung-drive.linagora.com/file'));
        $this->assertTrue($base->accepts('https://manh-drive.linagora.com/file'));
        $this->assertFalse($base->accepts('https://tung.manh-drive.linagora.com/file'));
        $this->assertFalse($base->accepts('https://tung-drive.linagora.com.evil.test/file'));
    }

    function testPathPrefixEndsAtASegmentBoundary() {
        $base = new TrustedUrlBase('https://drive.linagora.com/files');

        $this->assertTrue($base->accepts('https://drive.linagora.com/files/report.pdf'));
        $this->assertFalse($base->accepts('https://drive.linagora.com/files-evil/report.pdf'));
    }
}
