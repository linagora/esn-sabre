<?php

namespace ESN\CardDAV;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject;

/**
 * @medium
 */
class InlinePhotoPluginTest extends TestCase {

    private static function vcard($photoLine) {
        return "BEGIN:VCARD\r\n" .
            "VERSION:3.0\r\n" .
            "UID:inline-photo\r\n" .
            "FN:John Doe\r\n" .
            $photoLine . "\r\n" .
            "END:VCARD\r\n";
    }

    static function inlinePhotoProvider() {
        return [
            'vCard 3.0 ENCODING=b' => [self::vcard('PHOTO;ENCODING=b;TYPE=JPEG:dGVzdA==')],
            'ENCODING=BASE64' => [self::vcard('PHOTO;ENCODING=BASE64;TYPE=JPEG:dGVzdA==')],
            'lowercase encoding' => [self::vcard('PHOTO;encoding=b;TYPE=JPEG:dGVzdA==')],
            'VALUE=BINARY' => [self::vcard('PHOTO;VALUE=BINARY;TYPE=JPEG:dGVzdA==')],
            'grouped PHOTO' => [self::vcard('item1.PHOTO;ENCODING=b;TYPE=JPEG:dGVzdA==')],
            'vCard 2.1 bare BASE64' => [
                "BEGIN:VCARD\r\nVERSION:2.1\r\nFN:John Doe\r\nPHOTO;JPEG;BASE64:dGVzdA==\r\nEND:VCARD\r\n"
            ],
            'jCard ENCODING=b' => [json_encode([
                'vcard',
                [
                    ['version', new \stdClass(), 'text', '4.0'],
                    ['fn', new \stdClass(), 'text', 'John Doe'],
                    ['photo', ['encoding' => 'b', 'type' => 'JPEG'], 'binary', 'dGVzdA=='],
                ]
            ])],
            'vCard 4.0 data: URI' => [
                "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:John Doe\r\nPHOTO:data:image/jpeg;base64,dGVzdA==\r\nEND:VCARD\r\n"
            ],
            'vCard 3.0 VALUE=URI data: URI' => [self::vcard('PHOTO;VALUE=URI:data:image/jpeg;base64,dGVzdA==')],
            'uppercase DATA: URI' => [
                "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:John Doe\r\nPHOTO:DATA:image/jpeg;base64,dGVzdA==\r\nEND:VCARD\r\n"
            ],
            'jCard data: URI' => [json_encode([
                'vcard',
                [
                    ['version', new \stdClass(), 'text', '4.0'],
                    ['fn', new \stdClass(), 'text', 'John Doe'],
                    ['photo', new \stdClass(), 'uri', 'data:image/jpeg;base64,dGVzdA=='],
                ]
            ])],
        ];
    }

    static function acceptedProvider() {
        return [
            'URI photo' => [self::vcard('PHOTO;VALUE=URI:https://example.com/avatar.jpg')],
            'vCard 4.0 URI photo' => [
                "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:John Doe\r\nPHOTO:https://example.com/avatar.jpg\r\nEND:VCARD\r\n"
            ],
            'jCard URI photo' => [json_encode([
                'vcard',
                [
                    ['version', new \stdClass(), 'text', '4.0'],
                    ['fn', new \stdClass(), 'text', 'John Doe'],
                    ['photo', new \stdClass(), 'uri', 'https://example.com/avatar.jpg'],
                ]
            ])],
            'no photo' => [self::vcard('NOTE:no photo here')],
            'inline LOGO' => [self::vcard('LOGO;ENCODING=b;TYPE=PNG:dGVzdA==')],
            'data: URI outside PHOTO' => [self::vcard('NOTE:data:image/jpeg;base64,dGVzdA==')],
            'calendar with binary ATTACH' => [
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:abc\r\nDTSTART:20260613T063000Z\r\n" .
                "ATTACH;ENCODING=BASE64;VALUE=BINARY:dGVzdA==\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
            ],
            'malformed data' => ['not a vcard'],
        ];
    }

    function testConstructorRejectsUnknownMode() {
        $this->expectException(\InvalidArgumentException::class);

        new InlinePhotoPlugin('nope');
    }

    #[DataProvider('inlinePhotoProvider')]
    function testRejectThrowsOnInlinePhoto($data) {
        $plugin = new InlinePhotoPlugin(InlinePhotoPlugin::MODE_REJECT);
        $modified = false;

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);

        $this->invokeProcess($plugin, $data, $modified);
    }

    #[DataProvider('inlinePhotoProvider')]
    function testFilterIsTheDefaultMode($data) {
        $plugin = new InlinePhotoPlugin();
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertTrue($modified);
        $this->assertCount(0, VObject\Reader::read($data)->select('PHOTO'));
    }

    #[DataProvider('inlinePhotoProvider')]
    function testFilterStripsInlinePhoto($data) {
        $plugin = new InlinePhotoPlugin(InlinePhotoPlugin::MODE_FILTER);
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertTrue($modified);

        $vcard = VObject\Reader::read($data);
        $this->assertCount(0, $vcard->select('PHOTO'));
        $this->assertEquals('John Doe', (string) $vcard->FN);
    }

    #[DataProvider('inlinePhotoProvider')]
    function testAllowLeavesInlinePhotoUntouched($data) {
        $plugin = new InlinePhotoPlugin(InlinePhotoPlugin::MODE_ALLOW);
        $original = $data;
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertFalse($modified);
        $this->assertEquals($original, $data);
    }

    #[DataProvider('acceptedProvider')]
    function testRejectAcceptsDataWithoutInlinePhoto($data) {
        $plugin = new InlinePhotoPlugin(InlinePhotoPlugin::MODE_REJECT);
        $original = $data;
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertFalse($modified);
        $this->assertEquals($original, $data);
    }

    #[DataProvider('acceptedProvider')]
    function testFilterLeavesDataWithoutInlinePhotoUntouched($data) {
        $plugin = new InlinePhotoPlugin(InlinePhotoPlugin::MODE_FILTER);
        $original = $data;
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertFalse($modified);
        $this->assertEquals($original, $data);
    }

    function testFilterKeepsUriPhotoAndStripsInlineOne() {
        $plugin = new InlinePhotoPlugin(InlinePhotoPlugin::MODE_FILTER);
        $data = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:John Doe\r\n" .
            "PHOTO;ENCODING=b;TYPE=JPEG:dGVzdA==\r\n" .
            "PHOTO;VALUE=URI:https://example.com/avatar.jpg\r\n" .
            "END:VCARD\r\n";
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertTrue($modified);

        $vcard = VObject\Reader::read($data);
        $photos = $vcard->select('PHOTO');

        $this->assertCount(1, $photos);

        $remaining = reset($photos);
        $this->assertEquals('https://example.com/avatar.jpg', $remaining->getValue());
    }

    function testRejectThrowsOnInlinePhotoFromStreamPayload() {
        $plugin = new InlinePhotoPlugin(InlinePhotoPlugin::MODE_REJECT);
        $data = self::stream(self::vcard('PHOTO;ENCODING=b;TYPE=JPEG:dGVzdA=='));
        $modified = false;

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);

        $this->invokeProcess($plugin, $data, $modified);
    }

    /**
     * Sabre hands the request body over as a stream. Once consumed, it cannot
     * be read again, so the plugin must hand the payload back as a string.
     */
    function testAcceptedStreamPayloadIsHandedBackAsString() {
        $plugin = new InlinePhotoPlugin(InlinePhotoPlugin::MODE_REJECT);
        $vcard = self::vcard('PHOTO;VALUE=URI:https://example.com/avatar.jpg');
        $data = self::stream($vcard);
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertIsString($data);
        $this->assertEquals($vcard, $data);
    }

    function testAllowLeavesStreamPayloadUnread() {
        $plugin = new InlinePhotoPlugin(InlinePhotoPlugin::MODE_ALLOW);
        $vcard = self::vcard('PHOTO;ENCODING=b;TYPE=JPEG:dGVzdA==');
        $data = self::stream($vcard);
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertIsResource($data);
        $this->assertEquals($vcard, stream_get_contents($data));
    }

    private static function stream($content) {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * Calls the protected process() handler by reference.
     */
    private function invokeProcess(InlinePhotoPlugin $plugin, &$data, &$modified) {
        $method = new \ReflectionMethod($plugin, 'process');
        $method->setAccessible(true);

        $args = [&$data, &$modified];
        $method->invokeArgs($plugin, $args);
    }
}
