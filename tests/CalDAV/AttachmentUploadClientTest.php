<?php

namespace ESN\CalDAV;

use PHPUnit\Framework\TestCase;
use Sabre\HTTP\Client;
use Sabre\HTTP\Response;

class AttachmentUploadClientTest extends TestCase {
    function testUsesSideServiceContractAndForwardsUserAuthorization() {
        foreach (['Bearer user-token', 'Basic Ym9iOnBhc3N3b3Jk'] as $authorization) {
            $http = $this->createMock(Client::class);
            $http->expects($this->once())->method('send')->willReturnCallback(function ($request) use ($authorization) {
                $this->assertSame('POST', $request->getMethod());
                $this->assertSame('http://side-service:8080/api/attachments', $request->getUrl());
                $this->assertSame($authorization, $request->getHeader('Authorization'));
                $this->assertSame('application/octet-stream', $request->getHeader('Content-Type'));
                $this->assertSame('r%C3%A9sum%C3%A9%20%2B%25.bin', $request->getHeader('X-Filename'));
                $this->assertSame("\x00\xffbinary", $request->getBodyAsString());
                return new Response(201, [], json_encode(['uri' => 'https://drive.example.test/public?sharecode=test#/']));
            });
            $client = new AttachmentUploadClient('http://side-service:8080/api/attachments', $http);
            $this->assertSame('https://drive.example.test/public?sharecode=test#/',
                $client->upload($authorization, 'résumé +%.bin', 'application/octet-stream', "\x00\xffbinary"));
        }
    }

    function testRejectsInvalidUploadResults() {
        foreach (['{}', 'null', 'invalid json', '{"uri":42}', '{"uri":"file:///tmp/a"}',
            '{"uri":"https://user:password@drive.example.test/file"}', '{"uri":"https://drive.example.test/\\r\\n"}'] as $body) {
            $http = $this->createMock(Client::class);
            $http->expects($this->once())->method('send')->willReturn(new Response(201, [], $body));
            $client = new AttachmentUploadClient('http://side-service:8080/api/attachments', $http);
            try {
                $client->upload('Bearer user-token', 'file.bin', 'application/octet-stream', 'binary');
                $this->fail('Invalid upload response was accepted');
            } catch (\Sabre\DAV\Exception\ServiceUnavailable $error) {
                $this->assertSame('Attachment upload failed. Please retry saving the event.', $error->getMessage());
            }
        }
    }

    function testReportsUpstreamFailureWithoutLeakingBody() {
        foreach ([200, 302, 400, 401, 403, 413, 500, 503, 504] as $status) {
            $http = $this->createMock(Client::class);
            $http->expects($this->once())->method('send')->willReturn(new Response($status, [], 'sensitive-token'));
            $client = new AttachmentUploadClient('http://side-service:8080/api/attachments', $http);
            try {
                $client->upload('Bearer user-token', 'file.bin', 'application/octet-stream', 'binary');
                $this->fail('Unexpected status was accepted');
            } catch (\Sabre\DAV\Exception\ServiceUnavailable $error) {
                $this->assertSame('Attachment upload failed. Please retry saving the event.', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
        }
    }
}
