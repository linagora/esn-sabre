<?php
namespace ESN\CalDAV;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property\Boolean;
use Sabre\VObject\Property\Text;
use Sabre\VObject\Property\Uri;

class VObjectPropertyRegistry {
    // Register extension properties here so VObject parses them with the right value class.
    const array ICALENDAR_PROPERTY_TYPES = [
        'X-PUBLICLY-CREATED' => Boolean::class,
        'X-PUBLICLY-CREATOR' => Text::class,
        'X-PUBLICLY-DELETED' => Boolean::class,
        'X-OPENPAAS-BOOKING-LINK' => Text::class,
        'X-OPENPAAS-VIDEOCONFERENCE' => NullableUri::class,
    ];

    const array ICALENDAR_VALUE_TYPES = [
        'URI' => NullableUri::class,
    ];

    public static function register(): void {
        foreach (self::ICALENDAR_PROPERTY_TYPES as $propertyName => $propertyClass) {
            VCalendar::$propertyMap[$propertyName] = $propertyClass;
        }

        foreach (self::ICALENDAR_VALUE_TYPES as $valueType => $propertyClass) {
            VCalendar::$valueMap[$valueType] = $propertyClass;
        }
    }
}
