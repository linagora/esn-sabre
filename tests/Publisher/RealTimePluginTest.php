<?php
namespace ESN\Publisher;

#[\AllowDynamicProperties]
class RealTimePluginTest extends \PHPUnit\Framework\TestCase {

    protected $publisher;
    protected $plugin;

    function setUp(): void {
        $this->publisher = $this->createMock(Publisher::class);
        $this->plugin = $this->getMockBuilder('\ESN\Publisher\RealTimePlugin')
            ->setConstructorArgs([$this->publisher])
            ->onlyMethods(['buildData'])
            ->getMock();
    }

    function testCreateMessage() {
        $topic = 'topic';
        $data = 'data';

        $this->plugin->expects($this->any())
            ->method('buildData')
            ->with($data)
            ->willReturn($data);
        $this->plugin->createMessage($topic, $data);
        $messages = $this->plugin->getMessages();

        $this->assertEquals($messages[0]['topic'], $topic);
        $this->assertEquals($messages[0]['data'], $data);
    }

    function testPublishMessages() {

        $this->publisher->expects($this->once())->method('publish');
        $this->plugin->createMessage('topic', 'data');
        $this->plugin->publishMessages();
    }

    function testPublishMessagesMultipleMessages() {
        $this->publisher->expects($this->exactly(3))->method('publish');

        $this->plugin->createMessage('topic', 'data');
        $this->plugin->createMessage('topic', 'data');
        $this->plugin->createMessage('topic', 'data');
        $this->plugin->publishMessages();
    }

    function testPublishEmptyMessages() {

        $this->publisher->expects($this->never())->method('publish');

        $this->plugin->publishMessages();
    }

    function testPublishMessagesInjectsConnectedUser() {
        $authPlugin = $this->createMock(\Sabre\DAV\Auth\Plugin::class);
        $authPlugin->method('getCurrentPrincipal')->willReturn('principals/users/alice');
        $server = $this->createMock(\Sabre\DAV\Server::class);
        $server->method('getPlugin')->with('auth')->willReturn($authPlugin);
        $this->plugin->initialize($server);

        $data = ['foo' => 'bar'];
        $this->plugin->expects($this->any())->method('buildData')->with($data)->willReturn($data);

        $this->publisher->expects($this->once())->method('publish')
            ->with('topic', json_encode(['foo' => 'bar', 'connectedUser' => 'principals/users/alice']));

        $this->plugin->createMessage('topic', $data);
        $this->plugin->publishMessages();
    }

    function testPublishMessagesInjectsNullConnectedUserWhenNotAuthenticated() {
        $server = $this->createMock(\Sabre\DAV\Server::class);
        $server->method('getPlugin')->with('auth')->willReturn(null);
        $this->plugin->initialize($server);

        $data = ['foo' => 'bar'];
        $this->plugin->expects($this->any())->method('buildData')->with($data)->willReturn($data);

        $this->publisher->expects($this->once())->method('publish')
            ->with('topic', json_encode(['foo' => 'bar', 'connectedUser' => null]));

        $this->plugin->createMessage('topic', $data);
        $this->plugin->publishMessages();
    }

    function testPublishMessagesSubstitutesNonUtf8BytesInsteadOfPublishingFalse() {
        $data = ['event' => "ATTENDEE;CN=Ren\xE9:mailto:a@x"];
        $this->plugin->expects($this->any())->method('buildData')->with($data)->willReturn($data);

        $this->publisher->expects($this->once())->method('publish')
            ->with('topic', $this->callback(function($payload) {
                $decoded = json_decode($payload, true);

                return is_string($payload)
                    && $decoded['event'] === "ATTENDEE;CN=Ren\u{FFFD}:mailto:a@x";
            }));

        $this->plugin->createMessage('topic', $data);
        $this->plugin->publishMessages();
    }

    function testPublishMessagesConvertsASharedComponentToJcalOnceForTheBatch() {
        $event = new CountingVCalendar(['VEVENT' => ['UID' => 'shared', 'SUMMARY' => 'Stand-up']]);
        $other = new CountingVCalendar(['VEVENT' => ['UID' => 'other', 'SUMMARY' => 'Sanitised']]);
        $this->plugin->expects($this->any())->method('buildData')->willReturnArgument(0);

        $published = [];
        $this->publisher->expects($this->exactly(3))->method('publish')
            ->willReturnCallback(function($topic, $payload) use (&$published) {
                $published[] = $payload;
            });

        $this->plugin->createMessage('alarm', ['event' => $event, 'old_event' => $event]);
        $this->plugin->createMessage('subscriber', ['event' => $event]);
        $this->plugin->createMessage('invitee', ['event' => $other, 'old_event' => $event]);
        $this->plugin->publishMessages();

        $this->assertSame(1, $event->jsonSerializeCalls);
        $this->assertSame(1, $other->jsonSerializeCalls);
        $this->assertSame(json_encode(['event' => $event, 'old_event' => $event, 'connectedUser' => null]), $published[0]);
        $this->assertSame(json_encode(['event' => $other, 'old_event' => $event, 'connectedUser' => null]), $published[2]);
        $this->assertStringContainsString('Sanitised', $published[2]);
        $this->assertStringNotContainsString('Sanitised', $published[1]);
    }

    function testPublishMessagesBuildsEveryMessageBeforeConvertingSharedComponents() {
        $event = new CountingVCalendar(['VEVENT' => ['UID' => 'shared']]);
        $this->plugin->expects($this->any())->method('buildData')
            ->willReturnCallback(function($data) {
                if (isset($data['complete'])) {
                    $data['event']->PRODID = 'completed-by-buildData';
                }
                return $data;
            });

        $published = [];
        $this->publisher->method('publish')
            ->willReturnCallback(function($topic, $payload) use (&$published) {
                $published[] = $payload;
            });

        $this->plugin->createMessage('first', ['event' => $event]);
        $this->plugin->createMessage('second', ['event' => $event, 'complete' => true]);
        $this->plugin->publishMessages();

        $this->assertStringContainsString('completed-by-buildData', $published[0]);
        $this->assertStringContainsString('completed-by-buildData', $published[1]);
    }
}

class CountingVCalendar extends \Sabre\VObject\Component\VCalendar {
    public $jsonSerializeCalls = 0;

    function jsonSerialize(): array {
        $this->jsonSerializeCalls++;
        return parent::jsonSerialize();
    }
}
