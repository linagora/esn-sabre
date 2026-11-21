<?php

namespace ESN\CalDAV\Xml\Request;

use ESN\DAV\Xml\Element\Prop;
use Sabre\Xml\Reader;

/**
 * Sabre's MKCALENDAR body, with `{DAV:}prop` parsed by ESN's Prop (see there).
 * Sabre's class pushes its own map for `{DAV:}prop`, so the server-wide element
 * map alone does not reach it.
 */
class MkCalendar extends \Sabre\CalDAV\Xml\Request\MkCalendar {

    public static function xmlDeserialize(Reader $reader) {
        $self = new self();

        $elementMap = $reader->elementMap;
        $elementMap['{DAV:}prop'] = Prop::class;
        $elementMap['{DAV:}set'] = \Sabre\Xml\Element\KeyValue::class;
        $elems = $reader->parseInnerTree($elementMap);

        foreach ($elems as $elem) {
            if ('{DAV:}set' === $elem['name']) {
                $self->properties = array_merge($self->properties, $elem['value']['{DAV:}prop']);
            }
        }

        return $self;
    }
}
