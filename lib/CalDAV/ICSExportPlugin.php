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
            $renamedTzids = $this->exportTimezones($components, $timezones);
            $objects = array_merge($objects, $this->exportedObjects($components, $renamedTzids));

            // Destroy circular references to PHP will GC the object.
            $nodeComp->destroy();
        }

        foreach (array_merge($timezones->components(), $objects) as $component) {
            $calendar->add($component);
        }

        return $calendar;
    }

    /**
     * Adds the time zones of one object to the export.
     *
     * @param VObject\Component[] $components The components of the object
     * @param ExportedTimezones $timezones
     * @return array TZID in the object => TZID it is exported under, when they differ
     */
    private function exportTimezones(array $components, ExportedTimezones $timezones) {
        $renamedTzids = [];
        foreach ($this->firstDefinitions($components) as $vtimezone) {
            $tzid = (string) $vtimezone->TZID;
            $exportedTzid = $timezones->add($vtimezone);
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
     * @return VObject\Component[]
     */
    private function firstDefinitions(array $components) {
        $definitions = [];
        foreach ($components as $component) {
            if ($component->name === 'VTIMEZONE' && !isset($definitions[(string) $component->TZID])) {
                $definitions[(string) $component->TZID] = $component;
            }
        }

        return array_values($definitions);
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
