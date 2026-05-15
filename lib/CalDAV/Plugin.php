<?php
namespace ESN\CalDAV;

use ESN\CalDAV\Validation\CalendarObjectValidator;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;

/**
 * We can not directly use Sabre\CalDAV\Plugin because it's implementation of getCalendarHomeForPrincipal make a false assumption in the case of our DAV backend about the URL of users
 */
class Plugin extends \Sabre\CalDAV\Plugin {
    const PRIORITY_BEFORE_SCHEDULING = 80;
    const INPUT_TYPE_JCAL = 'jcal';
    const INPUT_TYPE_ICAL = 'ical';

    private $calendarObjectValidator;
    private $validatedInputTypes;
    protected $calendarObjectInputType;

    function __construct(?CalendarObjectValidator $calendarObjectValidator = null, ?array $validatedInputTypes = null) {
        $this->calendarObjectValidator = $calendarObjectValidator ?: new CalendarObjectValidator();
        $this->validatedInputTypes = $validatedInputTypes ?? [
            self::INPUT_TYPE_JCAL
        ];
    }

    function initialize(\Sabre\DAV\Server $server) {
        parent::initialize($server);
        $server->on('calendarObjectChange', [$this, 'validateCalendarObject'], self::PRIORITY_BEFORE_SCHEDULING);
    }

    /**
     * Returns the path to a principal's calendar home.
     *
     * The return url must not end with a slash.
     * This function should return null in case a principal did not have
     * a calendar home.
     *
     * @param string $principalUrl
     * @return string
     */
    function getCalendarHomeForPrincipal($principalUrl) {

        $parts = explode('/', trim($principalUrl, '/'));
        if (count($parts) !== 3) return;
        if ($parts[0] !== 'principals') return;
        if ($parts[1] !== 'users' || $parts[1] !== 'resources' ) {
            return self::CALENDAR_ROOT . '/' . $parts[2];
        }

        return;
    }

    protected function validateICalendar(&$data, $path, &$modified, RequestInterface $request, ResponseInterface $response, $isNew) {
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        $this->calendarObjectInputType = $this->detectCalendarObjectInputType($data);

        try {
            parent::validateICalendar($data, $path, $modified, $request, $response, $isNew);
        } finally {
            $this->calendarObjectInputType = null;
        }
    }

    function validateCalendarObject(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        if (!$this->shouldValidateCalendarObject($isNew)) {
            return;
        }

        $this->calendarObjectValidator->validate($vCal);
    }

    private function shouldValidateCalendarObject($isNew) {
        if (!$isNew) {
            return false;
        }

        return in_array($this->calendarObjectInputType, $this->validatedInputTypes, true);
    }

    protected function detectCalendarObjectInputType($data) {
        $data = ltrim((string) $data);

        return substr($data, 0, 1) === '[' ? self::INPUT_TYPE_JCAL : self::INPUT_TYPE_ICAL;
    }

}
