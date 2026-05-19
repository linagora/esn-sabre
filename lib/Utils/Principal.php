<?php

namespace ESN\Utils;

final class Principal implements \Stringable
{
    private readonly string $principal;

    public function __construct(
        string $prefix,
        string $value
    ) {
        $this->principal = $prefix . $value;
    }

    public function __toString(): string
    {
        return $this->principal;
    }
}
