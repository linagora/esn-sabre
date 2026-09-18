<?php

namespace ESN\CardDAV;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

    #[DataProvider('inlinePhotoProvider')]
    function testRejectsInlinePhotoByDefault($data) {
        $plugin = new InlinePhotoPlugin();

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);

        $this->invokeProcess($plugin, $data);
    }

    #[DataProvider('inlinePhotoProvider')]
    function testAllowLeavesInlinePhotoUntouched($data) {
        $plugin = new InlinePhotoPlugin(true);
        $original = $data;

        $this->invokeProcess($plugin, $data);

        $this->assertEquals($original, $data);
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
            'data: URI outside PHOTO' => [self::vcard('NOTE:data:image/jpeg;base64,dGVzdA==')],
            'no photo' => [self::vcard('NOTE:no photo here')],
            'inline LOGO' => [self::vcard('LOGO;ENCODING=b;TYPE=PNG:dGVzdA==')],
            'calendar with binary ATTACH' => [
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:abc\r\nDTSTART:20260613T063000Z\r\n" .
                "ATTACH;ENCODING=BASE64;VALUE=BINARY:dGVzdA==\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
            ],
            'malformed data' => ['not a vcard'],
        ];
    }

    #[DataProvider('acceptedProvider')]
    function testAcceptsDataWithoutInlinePhoto($data) {
        $plugin = new InlinePhotoPlugin();
        $original = $data;

        $this->invokeProcess($plugin, $data);

        $this->assertEquals($original, $data);
    }

    function testRejectsInlinePhotoFromStreamPayload() {
        $plugin = new InlinePhotoPlugin();
        $data = self::stream(self::vcard('PHOTO;ENCODING=b;TYPE=JPEG:dGVzdA=='));

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);

        $this->invokeProcess($plugin, $data);
    }

    /**
     * Sabre hands the request body over as a stream. Once consumed, it cannot
     * be read again, so the plugin must hand the payload back as a string.
     */
    function testAcceptedStreamPayloadIsHandedBackAsString() {
        $plugin = new InlinePhotoPlugin();
        $vcard = self::vcard('PHOTO;VALUE=URI:https://example.com/avatar.jpg');
        $data = self::stream($vcard);

        $this->invokeProcess($plugin, $data);

        $this->assertIsString($data);
        $this->assertEquals($vcard, $data);
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
    private function invokeProcess(InlinePhotoPlugin $plugin, &$data) {
        $method = new \ReflectionMethod($plugin, 'process');
        $method->setAccessible(true);

        $args = [&$data];
        $method->invokeArgs($plugin, $args);
    }
}
