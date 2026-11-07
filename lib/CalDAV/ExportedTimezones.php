<?php
namespace ESN\CalDAV;

use Sabre\VObject;

/**
 * The VTIMEZONEs of a calendar export. Each definition of a TZID is kept: one
 * that is neither the same text nor the same time zone as a definition
 * already exported is exported under a TZID of its own (Custom-2, ...).
 */
class ExportedTimezones {

    /**
     * TZID => [serialized VTIMEZONE => [TZID it is exported under, name of the zone it resolves to]]
     *
     * @var array
     */
    private $definitions = [];

    /**
     * Every TZID exported so far, as keys.
     *
     * @var array
     */
    private $usedTzids = [];

    /**
     * @var VObject\Component[]
     */
    private $components = [];

    /**
     * Adds $vtimezone, read in its own object, unless the same zone is already exported.
     *
     * @param VObject\Component $vtimezone
     * @return string The TZID it is exported under
     */
    public function add(VObject\Component $vtimezone) {
        $resolvedName = self::resolvedName($vtimezone);
        $exportedTzid = $this->find($vtimezone, $resolvedName);
        if ($exportedTzid !== null) {
            return $exportedTzid;
        }

        $tzid = (string) $vtimezone->TZID;
        $exportedTzid = $this->uniqueTzid($tzid);
        $timezone = clone $vtimezone;
        if ($exportedTzid !== $tzid) {
            $timezone->TZID = $exportedTzid;
        }

        $this->components[] = $timezone;
        $this->definitions[$tzid][$vtimezone->serialize()] = [$exportedTzid, $resolvedName];
        $this->usedTzids[$exportedTzid] = true;

        return $exportedTzid;
    }

    /**
     * @return VObject\Component[]
     */
    public function components() {
        return $this->components;
    }

    /**
     * The TZID an exported definition of the same zone is exported under:
     * the same text, or the same resolved time zone. Null when there is none.
     *
     * @param VObject\Component $vtimezone
     * @param string|null $resolvedName
     * @return string|null
     */
    private function find(VObject\Component $vtimezone, $resolvedName) {
        $definitions = $this->definitions[(string) $vtimezone->TZID] ?? [];
        $serialized = $vtimezone->serialize();
        if (isset($definitions[$serialized])) {
            return $definitions[$serialized][0];
        }

        // Clients write their own definition of the same well-known zone.
        foreach ($definitions as [$exportedTzid, $exportedName]) {
            if ($resolvedName !== null && $exportedName === $resolvedName) {
                return $exportedTzid;
            }
        }

        return null;
    }

    /**
     * $tzid, or $tzid followed by the first free "-N" suffix.
     *
     * @param string $tzid
     * @return string
     */
    private function uniqueTzid($tzid) {
        $candidate = $tzid;
        for ($suffix = 2; isset($this->usedTzids[$candidate]); $suffix++) {
            $candidate = $tzid . '-' . $suffix;
        }

        return $candidate;
    }

    /**
     * The name of the time zone a VTIMEZONE resolves to in its object, null
     * when it does not resolve.
     *
     * @param VObject\Component $vtimezone
     * @return string|null
     */
    private static function resolvedName(VObject\Component $vtimezone) {
        if (!$vtimezone instanceof VObject\Component\VTimeZone) {
            return null;
        }

        try {
            return $vtimezone->getTimeZone()->getName();
        } catch (\Exception $e) {
            return null;
        }
    }
}
