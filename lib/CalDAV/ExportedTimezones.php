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
     * Raw text of a VTIMEZONE in its object => TZID it is exported under.
     *
     * Only for a text whose serialized form is in $definitions: the TZID is
     * then the one add() returns for that text whatever object it is read in.
     *
     * @var array
     */
    private $byRaw = [];

    /**
     * TZID => [name of the zone it resolves to => TZID its first definition is exported under]
     *
     * @var array
     */
    private $byZone = [];

    /**
     * Every TZID exported so far, as keys.
     *
     * @var array
     */
    private $usedTzids = [];

    /**
     * TZID => the "-N" suffix to try first for its next definition. Every
     * smaller suffix is taken: $usedTzids only grows.
     *
     * @var array
     */
    private $nextSuffix = [];

    /**
     * @var VObject\Component[]
     */
    private $components = [];

    /**
     * Adds $vtimezone, read in its own object, unless the same zone is already exported.
     *
     * $raw is trusted as is: a text already seen gives the TZID recorded for
     * it, whatever $vtimezone is. It must be the exact text $vtimezone was
     * parsed from in a VCALENDAR, as ICSExportPlugin::rawTimezones() finds it
     * and pairs it by index; when that is not certain, pass null.
     *
     * @param VObject\Component $vtimezone
     * @param string|null $raw The text $vtimezone was read from, from its
     *                         BEGIN:VTIMEZONE line to its END:VTIMEZONE line
     *                         (without the line break after it), null when
     *                         it is not known for sure
     * @return string The TZID it is exported under
     */
    public function add(VObject\Component $vtimezone, ?string $raw = null) {
        // The same text as a definition already exported, which is the case of
        // nearly every object of a calendar: one text is read into one
        // component, so neither the serialization nor the zone is needed.
        if ($raw !== null && isset($this->byRaw[$raw])) {
            return $this->byRaw[$raw];
        }

        $tzid = (string) $vtimezone->TZID;
        $serialized = $vtimezone->serialize();

        // The same serialized text: no need to resolve the zone.
        if (isset($this->definitions[$tzid][$serialized])) {
            $exportedTzid = $this->definitions[$tzid][$serialized][0];
            if ($raw !== null) {
                $this->byRaw[$raw] = $exportedTzid;
            }

            return $exportedTzid;
        }

        $resolvedName = self::resolvedName($vtimezone);
        $exportedTzid = $this->findSameZone($tzid, $resolvedName);
        if ($exportedTzid !== null) {
            // Not recorded in $byRaw: the zone a text resolves to may depend
            // on the other VTIMEZONEs of its object.
            return $exportedTzid;
        }

        $exportedTzid = $this->uniqueTzid($tzid);
        $timezone = clone $vtimezone;
        if ($exportedTzid !== $tzid) {
            $timezone->TZID = $exportedTzid;
        }

        $this->components[] = $timezone;
        $this->definitions[$tzid][$serialized] = [$exportedTzid, $resolvedName];
        if ($raw !== null) {
            $this->byRaw[$raw] = $exportedTzid;
        }
        if ($resolvedName !== null) {
            $this->byZone[$tzid][$resolvedName] ??= $exportedTzid;
        }
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
     * The TZID an exported definition of $tzid resolving to the same time
     * zone is exported under, null when there is none.
     *
     * @param string $tzid
     * @param string|null $resolvedName
     * @return string|null
     */
    private function findSameZone($tzid, $resolvedName) {
        if ($resolvedName === null) {
            return null;
        }

        // Clients write their own definition of the same well-known zone.
        return $this->byZone[$tzid][$resolvedName] ?? null;
    }

    /**
     * $tzid, or $tzid followed by the first free "-N" suffix.
     *
     * The probe starts where the previous definition of $tzid stopped, rather
     * than from 2 again, so that n definitions of one TZID cost n probes, not
     * n²/2. A suffix that another object uses as its own TZID is still skipped.
     *
     * @param string $tzid
     * @return string
     */
    private function uniqueTzid($tzid) {
        if (!isset($this->usedTzids[$tzid])) {
            return $tzid;
        }

        $suffix = $this->nextSuffix[$tzid] ?? 2;
        while (isset($this->usedTzids[$tzid . '-' . $suffix])) {
            $suffix++;
        }
        $this->nextSuffix[$tzid] = $suffix + 1;

        return $tzid . '-' . $suffix;
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
