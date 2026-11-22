<?php

namespace ESN\DAV\Xml\Response;

use ESN\DAV\Xml\Element\Prop;
use Sabre\Xml\Reader;

/**
 * Sabre's multistatus, with a top-level `{DAV:}prop` parsed by ESN's Prop (see
 * there). Clients never send one, but `{DAV:}multistatus` is a root of the
 * server's element map, so any XML request body may carry it.
 */
class MultiStatus extends \Sabre\DAV\Xml\Response\MultiStatus {

    public static function xmlDeserialize(Reader $reader) {
        $elementMap = $reader->elementMap;
        $elementMap['{DAV:}prop'] = Prop::class;
        $elements = $reader->parseInnerTree($elementMap);

        $responses = [];
        $syncToken = null;

        if ($elements) {
            foreach ($elements as $elem) {
                if ('{DAV:}response' === $elem['name']) {
                    $responses[] = $elem['value'];
                }
                if ('{DAV:}sync-token' === $elem['name']) {
                    $syncToken = $elem['value'];
                }
            }
        }

        return new self($responses, $syncToken);
    }
}
