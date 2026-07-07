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
}