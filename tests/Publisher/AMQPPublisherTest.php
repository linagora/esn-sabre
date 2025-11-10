<?php

namespace ESN\Publisher;

use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

class AMQPPublisherTest extends TestCase {
    private $channel;
    private $publisher;

    function setUp(): void {
        $this->channel = $this->createMock(AMQPChannel::class);
        $this->publisher = new AMQPPublisher($this->channel);
    }

    function testPublishSendsMessageToChannel() {
        $topic = 'test.topic';
        $message = 'test message content';

        $this->channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function($msg) use ($message) {
                    return $msg instanceof AMQPMessage &&
                           $msg->body === utf8_encode($message);
                }),
                $topic
            );

        $this->publisher->publish($topic, $message);
    }

    function testPublishWithPropertiesSendsMessageWithProperties() {
        $topic = 'test.topic.with.props';
        $message = 'test message with properties';
        $properties = [
            'application_headers' => new \PhpAmqpLib\Wire\AMQPTable([
                'customHeader' => 'customValue',
                'userId' => '12345'
            ])
        ];

        $this->channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function($msg) use ($message, $properties) {
                    if (!($msg instanceof AMQPMessage)) {
                        return false;
                    }
                    if ($msg->body !== utf8_encode($message)) {
                        return false;
                    }
                    // Verify that properties were passed to AMQPMessage constructor
                    if (!isset($msg->get_properties()['application_headers'])) {
                        return false;
                    }
                    return true;
                }),
                $topic
            );

        $this->publisher->publishWithProperties($topic, $message, $properties);
    }

    function testPublishWithPropertiesConnectedUserHeader() {
        $topic = 'calendar:itip:deliver';
        $message = json_encode([
            'sender' => 'mailto:sender@example.com',
            'recipient' => 'mailto:recipient@example.com',
            'method' => 'REQUEST'
        ]);
        $properties = [
            'application_headers' => new \PhpAmqpLib\Wire\AMQPTable([
                'connectedUser' => 'sender@example.com'
            ])
        ];

        $this->channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function($msg) use ($message) {
                    if (!($msg instanceof AMQPMessage)) {
                        return false;
                    }
                    if ($msg->body !== utf8_encode($message)) {
                        return false;
                    }
                    $props = $msg->get_properties();
                    if (!isset($props['application_headers'])) {
                        return false;
                    }
                    $headers = $props['application_headers']->getNativeData();
                    return isset($headers['connectedUser']) &&
                           $headers['connectedUser'] === 'sender@example.com';
                }),
                $topic
            );

        $this->publisher->publishWithProperties($topic, $message, $properties);
    }

    function testPublishUtf8EncodesMessage() {
        $topic = 'test.topic';
        $message = 'Message avec des accents: é à ç';

        $this->channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function($msg) use ($message) {
                    return $msg->body === utf8_encode($message);
                }),
                $topic
            );

        $this->publisher->publish($topic, $message);
    }

    function testPublishWithPropertiesUtf8EncodesMessage() {
        $topic = 'test.topic';
        $message = 'Message avec des caractères spéciaux: €';
        $properties = [
            'application_headers' => new \PhpAmqpLib\Wire\AMQPTable([
                'test' => 'value'
            ])
        ];

        $this->channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function($msg) use ($message) {
                    return $msg->body === utf8_encode($message);
                }),
                $topic
            );

        $this->publisher->publishWithProperties($topic, $message, $properties);
    }

    function testPublishWithEmptyProperties() {
        $topic = 'test.topic';
        $message = 'test message';
        $properties = [];

        $this->channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function($msg) use ($message) {
                    return $msg instanceof AMQPMessage &&
                           $msg->body === utf8_encode($message);
                }),
                $topic
            );

        $this->publisher->publishWithProperties($topic, $message, $properties);
    }

    function testPublishWithMultipleHeaders() {
        $topic = 'test.topic';
        $message = 'test message';
        $properties = [
            'application_headers' => new \PhpAmqpLib\Wire\AMQPTable([
                'header1' => 'value1',
                'header2' => 'value2',
                'header3' => 123,
                'header4' => true
            ])
        ];

        $this->channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function($msg) {
                    if (!($msg instanceof AMQPMessage)) {
                        return false;
                    }
                    $props = $msg->get_properties();
                    if (!isset($props['application_headers'])) {
                        return false;
                    }
                    $headers = $props['application_headers']->getNativeData();
                    return isset($headers['header1']) &&
                           isset($headers['header2']) &&
                           isset($headers['header3']) &&
                           isset($headers['header4']) &&
                           $headers['header1'] === 'value1' &&
                           $headers['header2'] === 'value2' &&
                           $headers['header3'] === 123 &&
                           $headers['header4'] === true;
                }),
                $topic
            );

        $this->publisher->publishWithProperties($topic, $message, $properties);
    }
}
