<?php

namespace ESN\Publisher;

class RedisPublisher implements Publisher {
    function __construct($client) {
        $this->client = $client;
    }

    function publish($topic, $message) {
        $this->client->publish($topic, $message);
    }
}
