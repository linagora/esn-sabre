<?php

namespace ESN\CalDAV\Migration;

/**
 * Version 2 ran on the first upgraded replica while the replicas still on the
 * older code kept storing the CLASS of the first component only: the private
 * overrides they wrote after it were left stored as public. Run the same
 * idempotent repair again, once the deployments have finished that rollout.
 */
class Version0005RecomputePrivateOverrideClassificationAgain extends Version0002RecomputePrivateOverrideClassification
{
    public function getName(): string
    {
        return '0005_recompute_private_override_classification_again';
    }
}
