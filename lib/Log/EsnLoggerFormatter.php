<?php

namespace ESN\Log;

/**
 * Allows to add extra fields in tags
 *
 */
class EsnLoggerFormatter extends \Monolog\Formatter\ElasticaFormatter
{
    /**
     * {@inheritdoc}
     */
    public function format(array $record)
    {
        $formattedRecord = [];

        $record = parent::normalize($record);

        if (isset($record['context']) && isset($record['context']['exception'])) {
            $exception = $record['context']['exception'];
            $stacktrace = $exception['message']
                .'('.$exception['file']
                .'):'.PHP_EOL.implode(PHP_EOL, $exception['trace']);
        }

        $formattedRecord = [
            'message' => $record['message'],
            'severity' => $record['level_name'],
            '@timestamp' => $record['datetime'],
        ];

        if (isset($record['env'])) {
            $formattedRecord += $record['env'];
        }

        if (isset($stacktrace)) {
            $formattedRecord['stacktrace'] = $stacktrace;
        }

        return $this->getDocument($formattedRecord);
    }

}
