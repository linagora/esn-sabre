<?php

namespace ESN\CalDAV\Validation;

use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

/**
 * @internal
 */
class DateLikeValue {
    public const DATE = 'DATE';
    public const DATE_TIME = 'DATE-TIME';
    public const UTC = 'UTC';
    public const FLOATING = 'FLOATING';
    public const TZID_PREFIX = 'TZID:';

    private $name;
    private $value;
    private $valueType;
    private $form;

    private function __construct() {
    }

    static function fromUntil($value): self {
        $dateLikeValue = new self();
        $dateLikeValue->name = 'UNTIL';
        $dateLikeValue->value = (string)$value;
        $dateLikeValue->valueType = $dateLikeValue->detectValueType();
        $dateLikeValue->form = $dateLikeValue->dateTimeForm();

        return $dateLikeValue;
    }

    static function fromProperty(DateTimeProperty $property, $value = null): self {
        $dateLikeValue = new self();
        $dateLikeValue->name = $property->name;
        $dateLikeValue->value = $value ?: $property->getValue();
        $dateLikeValue->valueType = $property->getValueType();
        $dateLikeValue->form = $dateLikeValue->dateTimeForm((string)$property['TZID']);

        return $dateLikeValue;
    }

    function name(): string {
        return $this->name;
    }

    function value(): string {
        return $this->value;
    }

    function valueType(): ?string {
        return $this->valueType;
    }

    function form(): ?string {
        return $this->form;
    }

    function isKnown(): bool {
        return $this->valueType !== null;
    }

    function isDateTime(): bool {
        return $this->valueType === self::DATE_TIME;
    }

    function isUtc(): bool {
        return $this->form === self::UTC;
    }

    function requiresUtcUntil(): bool {
        return $this->isUtc() || str_starts_with($this->form ?: '', self::TZID_PREFIX);
    }

    function isValid(): bool {
        if ($this->valueType === self::DATE) {
            $dateTime = \DateTimeImmutable::createFromFormat(
                '!Ymd',
                $this->value,
                new \DateTimeZone('UTC')
            );

            return $dateTime && $dateTime->format('Ymd') === $this->value;
        }

        if ($this->valueType !== self::DATE_TIME) {
            return false;
        }

        $format = str_ends_with($this->value, 'Z') ? '!Ymd\THis\Z' : '!Ymd\THis';
        $expectedFormat = str_ends_with($this->value, 'Z') ? 'Ymd\THis\Z' : 'Ymd\THis';
        $dateTime = \DateTimeImmutable::createFromFormat(
            $format,
            $this->value,
            new \DateTimeZone('UTC')
        );

        return $dateTime && $dateTime->format($expectedFormat) === $this->value;
    }

    private function dateTimeForm($tzid = ''): ?string {
        if ($this->valueType === self::DATE) {
            return self::DATE;
        }

        if ($this->valueType !== self::DATE_TIME) {
            return null;
        }

        if (str_ends_with($this->value, 'Z')) {
            return self::UTC;
        }

        if ($tzid !== '') {
            return self::TZID_PREFIX.$tzid;
        }

        return self::FLOATING;
    }

    private function detectValueType(): ?string {
        if ($this->isBasicDateValue()) {
            return self::DATE;
        }

        if ($this->isBasicDateTimeValue()) {
            return self::DATE_TIME;
        }

        return null;
    }

    private function isBasicDateValue(): bool {
        return strlen($this->value) === 8 && ctype_digit($this->value);
    }

    private function isBasicDateTimeValue(): bool {
        $length = strlen($this->value);

        if ($length !== 15 && $length !== 16) {
            return false;
        }

        if ($this->value[8] !== 'T') {
            return false;
        }

        if ($length === 16 && $this->value[15] !== 'Z') {
            return false;
        }

        return ctype_digit(substr($this->value, 0, 8).substr($this->value, 9, 6));
    }
}
