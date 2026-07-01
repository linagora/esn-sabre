<?php

namespace ESN\Utils;

enum TenantType: int
{
    case User = 1;
    case Technical = 2;
    case Resources = 3;
    case TeamCalendars = 4;
}
