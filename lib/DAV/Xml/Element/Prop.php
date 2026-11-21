<?php

namespace ESN\DAV\Xml\Element;

use Sabre\DAV\Xml\Property\Complex;
use Sabre\Xml\Reader;

/**
 * Sabre's `{DAV:}prop` deserialiser, made to terminate on every well-formed body.
 *
 * Sabre's loop (sabre/dav 4.7.1) has two defects:
 *  - `<D:prop></D:prop>` (an end tag right after the start tag) is not seen as
 *    empty: the loop reads past its end tag and parses the following siblings
 *    as properties;
 *  - the `false` that `read()` returns at the end of the document is ignored,
 *    so once the reader runs out of nodes the loop never ends.
 * Together, `<D:prop></D:prop><D:set><D:prop></D:prop></D:set>` makes any
 * request whose body maps `{DAV:}prop` here (PROPPATCH, MKCOL, MKCALENDAR, and
 * PROPFIND with such a root) hold its PHP worker forever.
 *
 * This applies the checks the linagora fork of sabre/xml already makes in
 * `Deserializer\keyValue`.
 */
class Prop extends \Sabre\DAV\Xml\Element\Prop {

    public static function xmlDeserialize(Reader $reader) {
        if ($reader->isEmptyElement) {
            $reader->next();

            return [];
        }

        if (!$reader->read()) {
            return [];
        }

        if (Reader::END_ELEMENT === $reader->nodeType) {
            $reader->read();

            return [];
        }

        $values = [];

        do {
            if (Reader::ELEMENT === $reader->nodeType) {
                $clark = $reader->getClark();
                $values[$clark] = self::parseProperty($reader);
            } elseif (!$reader->read()) {
                return $values;
            }
        } while (Reader::END_ELEMENT !== $reader->nodeType);

        $reader->read();

        return $values;
    }

    /**
     * As Sabre's private `Prop::parseCurrentElement`: an element the map does
     * not know becomes a string or an XmlFragment, not a deep array.
     */
    private static function parseProperty(Reader $reader) {
        $name = $reader->getClark();

        if (!array_key_exists($name, $reader->elementMap)) {
            return Complex::xmlDeserialize($reader);
        }

        $deserializer = $reader->elementMap[$name];
        if (is_subclass_of($deserializer, \Sabre\Xml\XmlDeserializable::class)) {
            return call_user_func([$deserializer, 'xmlDeserialize'], $reader);
        }
        if (is_callable($deserializer)) {
            return call_user_func($deserializer, $reader);
        }

        $type = is_object($deserializer) ? 'object (' . get_class($deserializer) . ')' : gettype($deserializer);
        throw new \LogicException('Could not use this type as a deserializer: ' . $type);
    }
}
