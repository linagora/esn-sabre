<?php

namespace ESN\CalDAV;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property\Uri;

class NullableUri extends Uri
{
    #[\ReturnTypeWillChange]
    public function offsetSet($name, $value)
    {
        if (!$this->isNullableProperty()
            && strtoupper((string)$name) === 'VALUE'
            && strtoupper((string)$value) === 'URI'
            && !isset($this->parameters['VALUE'])) {
            return;
        }

        parent::offsetSet($name, $value);
    }

    public function setJsonValue(array $value)
    {
        parent::setJsonValue($this->isNullableProperty() && [null] === $value ? [''] : $value);
    }

    private function isNullableProperty(): bool
    {
        return (VCalendar::$propertyMap[$this->name] ?? null) === self::class;
    }
}