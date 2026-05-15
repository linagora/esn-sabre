<?php

namespace ESN\DAV\Auth\Backend;

enum TenantType: int
{
    case User = 1;
    case Technical = 2;
    case Resources = 3;
}
