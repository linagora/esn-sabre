<?php

namespace ESN\DAV\Auth\Backend;

final class Principal implements \Stringable
{
    public function __construct(
        private string $prefix,
        private string $value
    ) {
    }

    public function __toString(): string
    {
        return $this->prefix . $this->value;
    }
}
