<?php

namespace ESN\Utils\Migration;

use MongoDB\Database;
use Psr\Log\LoggerInterface;

interface Migration
{
    public function getName(): string;

    // Forward-only migrations must be safe to rerun after a partial failure.
    public function up(Database $database, LoggerInterface $logger): void;
}
