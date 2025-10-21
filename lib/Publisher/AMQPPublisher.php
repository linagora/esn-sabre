<?php
namespace ESN\Publisher;

class AMQPPublisher {

    function __construct($channel) {
        $this->channel = $channel;

        if (!$this->channel) {
            error_log('Please provide a non null channel when creating a RabbitmqClient');
        }
    }

    function publish($topic, $message) {
        $msg = new \PhpAmqpLib\Message\AMQPMessage(utf8_encode($message));
        $this->channel->basic_publish($msg, $topic);
    }
}
