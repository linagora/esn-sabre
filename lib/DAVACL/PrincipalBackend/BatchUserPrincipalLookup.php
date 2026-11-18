<?php

namespace ESN\DAVACL\PrincipalBackend;

/**
 * A principal backend able to resolve many user e-mail addresses with a few queries.
 */
interface BatchUserPrincipalLookup {

    /**
     * Resolves the user principals of $addresses together, then runs $operation: while it
     * runs, findByUri('mailto:<address>', 'principals/users') answers from those results,
     * hits and misses alike, instead of querying each address on its own.
     */
    function withUserPrincipalsResolved(array $addresses, callable $operation);
}
