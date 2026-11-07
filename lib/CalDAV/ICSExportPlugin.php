<?php
namespace ESN\CalDAV;

use Sabre\DAV;
use Sabre\VObject;

/**
 * Sabre's ICS export, with the merge of the calendar objects fixed.
 *
 * - Only the components of each object are merged: sabre/dav 4.7.1 also
 *   looks at its properties, and a property named VTIMEZONE makes the
 *   export fail (linagora/sabre-vobject#172).
 */
class ICSExportPlugin extends \Sabre\CalDAV\ICSExportPlugin {

    /**
     * Merges all calendar objects, and builds one big iCalendar blob.
     *
     * @param array $properties Some CalDAV properties
     * @param array $inputObjects The calendar data of the objects
     * @return VObject\Component\VCalendar
     */
    public function mergeObjects(array $properties, array $inputObjects) {
        $calendar = new VObject\Component\VCalendar();
        $calendar->VERSION = '2.0';
        if (DAV\Server::$exposeVersion) {
            $calendar->PRODID = '-//SabreDAV//SabreDAV ' . DAV\Version::VERSION . '//EN';
        } else {
            $calendar->PRODID = '-//SabreDAV//SabreDAV//EN';
        }
        if (isset($properties['{DAV:}displayname'])) {
            $calendar->{'X-WR-CALNAME'} = $properties['{DAV:}displayname'];
        }
        if (isset($properties['{http://apple.com/ns/ical/}calendar-color'])) {
            $calendar->{'X-APPLE-CALENDAR-COLOR'} = $properties['{http://apple.com/ns/ical/}calendar-color'];
        }

        $collectedTimezones = [];

        $timezones = [];
        $objects = [];

        foreach ($inputObjects as $inputObject) {
            $nodeComp = VObject\Reader::read($inputObject);

            // getComponents(), not children(): a property of the VCALENDAR
            // named like a component is not one.
            foreach ($nodeComp->getComponents() as $child) {
                switch ($child->name) {
                    case 'VEVENT':
                    case 'VTODO':
                    case 'VJOURNAL':
                        $objects[] = clone $child;
                        break;

                    // VTIMEZONE is special, because we need to filter out the duplicates
                    case 'VTIMEZONE':
                        // Naively just checking tzid.
                        if (in_array((string) $child->TZID, $collectedTimezones)) {
                            break;
                        }

                        $timezones[] = clone $child;
                        $collectedTimezones[] = (string) $child->TZID;
                        break;
                }
            }
            // Destroy circular references to PHP will GC the object.
            $nodeComp->destroy();
            unset($nodeComp);
        }

        foreach ($timezones as $tz) {
            $calendar->add($tz);
        }
        foreach ($objects as $obj) {
            $calendar->add($obj);
        }

        return $calendar;
    }
}
