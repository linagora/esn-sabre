<?php

namespace ESN\Utils\Migration;

// Only operational messages without contact payloads or credentials belong in this exception.
class MigrationException extends \RuntimeException
{
}
