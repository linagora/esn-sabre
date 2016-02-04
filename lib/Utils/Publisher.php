<?php

namespace ESN\Utils;

interface Publisher {

    /**
     * This method publish a message in a specified topic.
     * @method publish
     * @return nothing
     */
    public function publish($topic, $message);
}
