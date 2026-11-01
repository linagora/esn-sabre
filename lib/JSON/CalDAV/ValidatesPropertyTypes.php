<?php

namespace ESN\JSON\CalDAV;

/**
 * Trait for checking the JSON type of client supplied collection properties
 * before they reach the storage backend.
 */
trait ValidatesPropertyTypes {
    /**
     * Returns true when one of the given JSON properties holds a value of an
     * unexpected type. Absent and null values are accepted.
     *
     * apple:order must be an integer (or a string holding an integer), every
     * other property must be a string.
     *
     * @param mixed $jsonData The decoded JSON object
     * @param string[] $jsonProps The JSON property names to check
     * @return bool
     */
    private function hasIllTypedProperty($jsonData, array $jsonProps): bool {
        return !empty(array_filter($jsonProps, fn($prop) =>
            !$this->isWellTypedPropertyValue($prop, $jsonData->$prop ?? null)));
    }

    private function isWellTypedPropertyValue(string $jsonProp, $value): bool {
        if ($value === null) {
            return true;
        }

        if ($jsonProp === 'apple:order') {
            return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
        }

        return is_string($value);
    }
}
