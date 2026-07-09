<?php

namespace App\Enums;

/**
 * OpenMediaUpdateRequestModel.Action for PUT /api/v1/openmedia/{id}
 * (MiCC SDK ActionType enum: 0 = Complete, 1 = Reroute).
 */
enum MitelWorkItemAction: int
{
    case Complete = 0;
    case Reroute = 1;
}
