<?php

namespace ESN\Log;

/**
 * Allows to add extra fields in tags
 *
 */
class EsnLoggerProcessor implements \Monolog\Processor\ProcessorInterface
{
    private $envFields;

    public function __construct(array $fields)
    {
        $extras = [];

        foreach ($fields as $key => $value) {
            $extras[$key] = getenv($value);
        }

        $this->envFields = $extras;
    }

    public function __invoke(array $record)
    {
        $record['env'] = $this->envFields;

        return $record;
    }
}
