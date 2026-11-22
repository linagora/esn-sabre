<?php

namespace ESN\DAV\Xml\Request;

use ESN\DAV\Xml\Element\Prop;
use Sabre\Xml\Reader;

/**
 * Sabre's PROPPATCH body, with `{DAV:}prop` parsed by ESN's Prop (see there).
 * Sabre's class pushes its own map for `{DAV:}prop`, so the server-wide element
 * map alone does not reach it.
 */
class PropPatch extends \Sabre\DAV\Xml\Request\PropPatch {

    public static function xmlDeserialize(Reader $reader) {
        $self = new self();

        $elementMap = $reader->elementMap;
        $elementMap['{DAV:}prop'] = Prop::class;
        $elementMap['{DAV:}set'] = \Sabre\Xml\Element\KeyValue::class;
        $elementMap['{DAV:}remove'] = \Sabre\Xml\Element\KeyValue::class;

        $elems = $reader->parseInnerTree($elementMap);

        foreach ($elems as $elem) {
            if ('{DAV:}set' === $elem['name']) {
                $self->properties = array_merge($self->properties, $elem['value']['{DAV:}prop']);
            }
            if ('{DAV:}remove' === $elem['name']) {
                foreach ($elem['value']['{DAV:}prop'] as $remove => $value) {
                    $self->properties[$remove] = null;
                }
            }
        }

        return $self;
    }
}
