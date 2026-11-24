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

    // GHSA-7f3v-466j-qf9g
    #[DataProvider('pathEscapes')]
    function testRejectsUrlsResolvingOutsideTheBasePath($url) {
        $this->assertFalse((new TrustedUrlBase('http://files.example.com:8080/a'))->accepts($url));
    }

    static function pathEscapes() {
        return [
            'dot segment' => ['http://files.example.com:8080/a/../o'],
            'percent-encoded dot segment' => ['http://files.example.com:8080/a/%2e%2e/o'],
            'backslash' => ['http://files.example.com:8080/a/..\\o'],
            'trailing dot segment' => ['http://files.example.com:8080/a/..'],
            'upper-case percent-encoded dot segment' => ['http://files.example.com:8080/a/%2E%2E/o'],
            'mixed dot segment' => ['http://files.example.com:8080/a/.%2e/o'],
            'single dot segment' => ['http://files.example.com:8080/a/./b'],
            'encoded slash' => ['http://files.example.com:8080/a/..%2fo'],
            'encoded backslash' => ['http://files.example.com:8080/a/..%5Co'],
            'tab inside' => ["http://files.example.com:8080/a/.\t./o"],
            'line feed inside' => ["http://files.example.com:8080/a/.\n./o"],
            'space inside' => ['http://files.example.com:8080/a/ ../o'],
            'userinfo' => ['http://user@files.example.com:8080/a/room'],
            'empty port' => ['http://files.example.com:/a/room'],
            'outside the path' => ['http://files.example.com:8080/o'],
            'backslash in the authority' => ['http://files.example.com:8080\\@evil.test/a/room'],
            'tab in the query' => ["http://files.example.com:8080/a/room?x=\ty"],
        ];
    }

    static function acceptedUnderThePath() {
        return [
            'the path itself' => ['http://files.example.com:8080/a'],
            'below the path' => ['http://files.example.com:8080/a/room'],
            'dots inside a name' => ['http://files.example.com:8080/a/..room/x.y..'],
            'query and fragment' => ['http://files.example.com:8080/a/room?x=../y#..'],
            'upper-case scheme and host' => ['HTTP://FILES.EXAMPLE.COM:8080/a/room'],
            'leading zeros in the port' => ['http://files.example.com:08080/a/room'],
            'surrounding spaces' => ['  http://files.example.com:8080/a/room  '],
            'other percent-encoding' => ['http://files.example.com:8080/a/r%20oom'],
            'encoded slash in the query' => ['http://files.example.com:8080/a/x?path=%2Fdocs%2Freport.pdf&sig=ab%2Fcd%5C'],
            'space and backslash in the query' => ['http://files.example.com:8080/a/x?name=a b\\c#frag ment'],
        ];
    }

    #[DataProvider('acceptedUnderThePath')]
    function testAcceptsUrlsUnderTheBasePath($url) {
        $this->assertTrue((new TrustedUrlBase('http://files.example.com:8080/a'))->accepts($url));
    }

    function testRejectsPortsBrowsersDoNotParse() {
        $this->assertFalse((new TrustedUrlBase('https://visio.example.com'))->accepts('https://visio.example.com:443y'));
        $this->assertFalse((new TrustedUrlBase('http://files.example.com:8080/a'))->accepts('http://files.example.com:8080]/a/room'));
    }
}
