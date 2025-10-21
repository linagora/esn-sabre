<?php
namespace ESN\Publisher;

class RealTimePluginTest extends \PHPUnit\Framework\TestCase {

    protected $publisher;
    protected $plugin;

    function setUp(): void {
        $this->publisher = $this->createMock(Publisher::class);
        $this->plugin = $this->getMockForAbstractClass('\ESN\Publisher\RealTimePlugin', [$this->publisher]);
    }

    function testCreateMessage() {
        $topic = 'topic';
        $data = 'data';

        $this->plugin->expects($this->any())
            ->method('buildData')
            ->with($data)
            ->will($this->returnValue($data));
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
}