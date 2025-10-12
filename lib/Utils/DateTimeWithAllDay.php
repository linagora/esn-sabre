<?php

namespace ESN\Utils;

/**
 * Wrapper class for DateTimeImmutable with isAllDay flag
 *
 * This class wraps a DateTimeImmutable instance and adds an isAllDay property
 * to indicate whether the datetime represents an all-day event.
 * It delegates all method calls to the wrapped DateTimeImmutable instance.
 */
class DateTimeWithAllDay implements \JsonSerializable {
    private \DateTimeImmutable $dateTime;
    public bool $isAllDay;

    public function __construct(\DateTimeImmutable $dateTime, bool $isAllDay = false) {
        $this->dateTime = $dateTime;
        $this->isAllDay = $isAllDay;
    }

    /**
     * Get the wrapped DateTimeImmutable instance
     */
    public function getDateTime(): \DateTimeImmutable {
        return $this->dateTime;
    }

    /**
     * Delegate all method calls to the wrapped DateTimeImmutable
     */
    public function __call(string $name, array $arguments) {
        return call_user_func_array([$this->dateTime, $name], $arguments);
    }

    /**
     * Allow property access to DateTimeImmutable properties
     */
    public function __get(string $name) {
        return $this->dateTime->$name;
    }

    /**
     * String representation
     */
    public function __toString(): string {
        return $this->dateTime->format('c');
    }

    /**
     * JSON serialization
     * Serializes as a DateTimeImmutable object with an additional isAllDay property
     * Note: Property order matters for test compatibility
     */
    public function jsonSerialize(): mixed {
        // Create a stdClass that mimics DateTimeImmutable with isAllDay
        // Order matters: isAllDay must come first to match existing test expectations
        $obj = new \stdClass();
        $obj->isAllDay = $this->isAllDay;
        $obj->date = $this->dateTime->format('Y-m-d H:i:s.u');
        $obj->timezone_type = 3;
        $obj->timezone = $this->dateTime->getTimezone()->getName();
        return $obj;
    }
}
