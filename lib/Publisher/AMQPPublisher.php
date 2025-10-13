<?php
namespace ESN\Publisher;

class AMQPPublisher {

    protected $channel;

    function __construct($channel) {
        $this->channel = $channel;

        if (!$this->channel) {
            error_log('Please provide a non null channel when creating a RabbitmqClient');
        }
    }

    function publish($topic, $message) {
        $this->channel->exchange_declare($topic, 'fanout', true, false, false); //this correspond to passive: true, durable: false, auto_delete: false
        $msg = new \PhpAmqpLib\Message\AMQPMessage(utf8_encode($message));

        $this->channel->basic_publish($msg, $topic);
    }
}
