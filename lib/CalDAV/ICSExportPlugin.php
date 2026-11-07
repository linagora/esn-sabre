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
 * - Two objects defining one TZID differently both keep their definition:
 *   sabre/dav 4.7.1 keeps the first one only, and the events of the other
 *   object are exported at the wrong time (linagora/sabre-vobject#173). The
 *   second definition is exported under a TZID of its own, which the TZID
 *   parameters of its object are rewritten to.
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

        // TZID => [serialized VTIMEZONE => [TZID it is exported under, name of the zone it resolves to]]
        $collectedTimezones = [];
        // Every TZID exported so far
        $usedTzids = [];

        $timezones = [];
        $objects = [];

        foreach ($inputObjects as $inputObject) {
            $nodeComp = VObject\Reader::read($inputObject);

            // getComponents(), not children(): a property of the VCALENDAR
            // named like a component is not one.
            $components = $nodeComp->getComponents();

            // TZID in this object => TZID it is exported under, when it differs
            $renamedTzids = [];
            $seenTzids = [];
            foreach ($components as $child) {
                if ($child->name !== 'VTIMEZONE') {
                    continue;
                }

                $tzid = (string) $child->TZID;
                if (isset($seenTzids[$tzid])) {
                    // The object itself reads its TZID with its first definition.
                    continue;
                }
                $seenTzids[$tzid] = true;

                $exportedTzid = $this->findCollectedTimezone($collectedTimezones, $child);
                if ($exportedTzid === null) {
                    $exportedTzid = $this->uniqueTzid($tzid, $usedTzids);

                    $timezone = clone $child;
                    if ($exportedTzid !== $tzid) {
                        $timezone->TZID = $exportedTzid;
                    }
                    $timezones[] = $timezone;
                    $collectedTimezones[$tzid][$child->serialize()] = [$exportedTzid, $this->resolvedTimezoneName($child)];
                    $usedTzids[$exportedTzid] = true;
                }
                if ($exportedTzid !== $tzid) {
                    $renamedTzids[$tzid] = $exportedTzid;
                }
            }

            foreach ($components as $child) {
                switch ($child->name) {
                    case 'VEVENT':
                    case 'VTODO':
                    case 'VJOURNAL':
                        $object = clone $child;
                        if ($renamedTzids) {
                            $this->renameTzidParameters($object, $renamedTzids);
                        }
                        $objects[] = $object;
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

    /**
     * The TZID an already collected definition of the TZID of $vtimezone is
     * exported under, when it is the same zone: the same serialization, or
     * the same resolved time zone. Null when there is none.
     *
     * @param array $collectedTimezones
     * @param VObject\Component $vtimezone
     * @return string|null
     */
    private function findCollectedTimezone(array $collectedTimezones, VObject\Component $vtimezone) {
        $tzid = (string) $vtimezone->TZID;
        if (!isset($collectedTimezones[$tzid])) {
            return null;
        }

        $serialized = $vtimezone->serialize();
        if (isset($collectedTimezones[$tzid][$serialized])) {
            return $collectedTimezones[$tzid][$serialized][0];
        }

        // Clients write their own definition of the same well-known zone.
        $resolvedName = $this->resolvedTimezoneName($vtimezone);
        if ($resolvedName !== null) {
            foreach ($collectedTimezones[$tzid] as list($exportedTzid, $collectedName)) {
                if ($collectedName === $resolvedName) {
                    return $exportedTzid;
                }
            }
        }

        return null;
    }

    /**
     * The name of the time zone a VTIMEZONE resolves to in its object, null
     * when it does not resolve.
     *
     * @param VObject\Component $vtimezone
     * @return string|null
     */
    private function resolvedTimezoneName(VObject\Component $vtimezone) {
        if (!$vtimezone instanceof VObject\Component\VTimeZone) {
            return null;
        }

        try {
            return $vtimezone->getTimeZone()->getName();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * $tzid, or $tzid followed by the first free "-N" suffix.
     *
     * @param string $tzid
     * @param array $usedTzids TZID => true
     * @return string
     */
    private function uniqueTzid($tzid, array $usedTzids) {
        if (!isset($usedTzids[$tzid])) {
            return $tzid;
        }

        $suffix = 2;
        while (isset($usedTzids[$tzid . '-' . $suffix])) {
            $suffix++;
        }

        return $tzid . '-' . $suffix;
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
            } elseif (isset($child['TZID'])) {
                $tzid = (string) $child['TZID'];
                if (isset($renamedTzids[$tzid])) {
                    $child['TZID'] = $renamedTzids[$tzid];
                }
            }
        }
    }
}
