<?php
namespace ESN\CalDAV;

use Sabre\VObject;

/**
 * Sabre's ICS export, with the merge of the calendar objects fixed.
 *
 * - Only the components of each object are merged: sabre/dav 4.7.1 also
 *   looks at its properties, and a property named VTIMEZONE makes the
 *   export fail (linagora/sabre-vobject#172).
 * - Two objects defining one TZID differently both keep their definition:
 *   sabre/dav 4.7.1 keeps the first one only, and the events of the other
 *   object are exported at the wrong time (linagora/sabre-vobject#173). The
 *   second definition is exported under a TZID of its own, which the TZID
 *   parameters of its object are rewritten to.
 */
class ICSExportPlugin extends \Sabre\CalDAV\ICSExportPlugin {

    const OBJECT_COMPONENTS = ['VEVENT', 'VTODO', 'VJOURNAL'];

    /**
     * A line folded onto the next one that is, or may become once unfolded,
     * a BEGIN or END line: shorter than "BEGIN:", or starting like one.
     */
    const FOLDED_DELIMITER = '/(?:\A|(?<=\n))(?:(?i:BEGIN:|END:)[^\n]*|[^\r\n]{0,5}\r*)\n[ \t]/';

    /**
     * A BEGIN:VTIMEZONE line, as the parser reads it (ignoring the case and the trailing CRs).
     */
    const VTIMEZONE_BEGIN = '/(?<=\n)BEGIN:VTIMEZONE\r*\n/i';

    /**
     * A VTIMEZONE, from its BEGIN line to the first END:VTIMEZONE line.
     */
    const VTIMEZONE_BLOCK = '/(?<=\n)BEGIN:VTIMEZONE\r*\n.*?(?<=\n)END:VTIMEZONE\r*(?=\n|\z)/is';

    /**
     * Merges all calendar objects, and builds one big iCalendar blob.
     *
     * @param array $properties Some CalDAV properties
     * @param array $inputObjects The calendar data of the objects
     * @return VObject\Component\VCalendar
     */
    public function mergeObjects(array $properties, array $inputObjects) {
        // The calendar itself, with its properties and no object.
        $calendar = parent::mergeObjects($properties, []);

        $timezones = new ExportedTimezones();
        $objects = [];
        foreach ($inputObjects as $inputObject) {
            $nodeComp = VObject\Reader::read($inputObject);

            // getComponents(), not children(): a property of the VCALENDAR
            // named like a component is not one.
            $components = $nodeComp->getComponents();
            $raws = $nodeComp instanceof VObject\Component\VCalendar
                ? $this->rawTimezones($inputObject, $components)
                : [];
            $renamedTzids = $this->exportTimezones($components, $raws, $timezones);
            // Appended one by one: array_merge() would copy the growing list for every object.
            foreach ($this->exportedObjects($components, $renamedTzids) as $object) {
                $objects[] = $object;
            }

            // Destroy circular references to PHP will GC the object.
            $nodeComp->destroy();
        }

        foreach (array_merge($timezones->components(), $objects) as $component) {
            $calendar->add($component);
        }

        return $calendar;
    }

    /**
     * The text of each VTIMEZONE of $components, in order, as read from
     * $input. Empty when it cannot be told for sure which text is which
     * VTIMEZONE: ExportedTimezones then serializes them.
     *
     * Once no BEGIN or END line is folded, every BEGIN:VTIMEZONE line of the
     * document opens a VTIMEZONE. As many of them as top-level VTIMEZONEs
     * means that none is nested in another component and that none follows
     * the end of the document, which the parser ignores. The first
     * END:VTIMEZONE line after each one then closes it.
     *
     * @param mixed $input The calendar data of the object
     * @param VObject\Component[] $components The components read from it
     * @return string[] Index of the VTIMEZONE among the VTIMEZONEs of $components => its text
     */
    private function rawTimezones($input, array $components) {
        $count = 0;
        foreach ($components as $component) {
            if ($component->name === 'VTIMEZONE') {
                $count++;
            }
        }

        if ($count === 0
            || !is_string($input)
            || preg_match(self::FOLDED_DELIMITER, $input) !== 0
            || preg_match_all(self::VTIMEZONE_BEGIN, $input) !== $count
            || preg_match_all(self::VTIMEZONE_BLOCK, $input, $matches) !== $count) {
            return [];
        }

        return $matches[0];
    }

    /**
     * Adds the time zones of one object to the export.
     *
     * @param VObject\Component[] $components The components of the object
     * @param string[] $raws The text of each VTIMEZONE, see rawTimezones()
     * @param ExportedTimezones $timezones
     * @return array TZID in the object => TZID it is exported under, when they differ
     */
    private function exportTimezones(array $components, array $raws, ExportedTimezones $timezones) {
        $renamedTzids = [];
        foreach ($this->firstDefinitions($components) as $index => $vtimezone) {
            $tzid = (string) $vtimezone->TZID;
            $exportedTzid = $timezones->add($vtimezone, $raws[$index] ?? null);
            if ($exportedTzid !== $tzid) {
                $renamedTzids[$tzid] = $exportedTzid;
            }
        }

        return $renamedTzids;
    }

    /**
     * The first VTIMEZONE of each TZID: the object itself reads its TZID with it.
     *
     * @param VObject\Component[] $components
     * @return VObject\Component[] Index of the VTIMEZONE among the VTIMEZONEs of $components => VTIMEZONE
     */
    private function firstDefinitions(array $components) {
        $definitions = [];
        $tzids = [];
        $index = 0;
        foreach ($components as $component) {
            if ($component->name !== 'VTIMEZONE') {
                continue;
            }
            $tzid = (string) $component->TZID;
            if (!isset($tzids[$tzid])) {
                $tzids[$tzid] = true;
                $definitions[$index] = $component;
            }
            $index++;
        }

        return $definitions;
    }

    /**
     * Copies of the objects of $components, with their TZID parameters renamed.
     *
     * @param VObject\Component[] $components
     * @param array $renamedTzids TZID => new TZID
     * @return VObject\Component[]
     */
    private function exportedObjects(array $components, array $renamedTzids) {
        $objects = [];
        foreach ($components as $component) {
            if (in_array($component->name, self::OBJECT_COMPONENTS, true)) {
                $object = clone $component;
                $this->renameTzidParameters($object, $renamedTzids);
                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * Rewrites the TZID parameters of the properties of $component and of its
     * sub-components (VALARM...).
     *
     * @param VObject\Component $component
     * @param array $renamedTzids TZID => new TZID
     * @return void
     */
    private function renameTzidParameters(VObject\Component $component, array $renamedTzids) {
        foreach ($component->children() as $child) {
            if ($child instanceof VObject\Component) {
                $this->renameTzidParameters($child, $renamedTzids);
            } else {
                $this->renameTzidParameter($child, $renamedTzids);
            }
        }
    }

    /**
     * @param VObject\Property $property
     * @param array $renamedTzids TZID => new TZID
     * @return void
     */
    private function renameTzidParameter(VObject\Property $property, array $renamedTzids) {
        if (!isset($property['TZID'])) {
            return;
        }

        $tzid = (string) $property['TZID'];
        if (isset($renamedTzids[$tzid])) {
            $property['TZID'] = $renamedTzids[$tzid];
        }
    }
}
