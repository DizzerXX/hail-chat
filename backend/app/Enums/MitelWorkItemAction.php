<?php

namespace App\Enums;

/**
 * OpenMediaUpdateRequestModel.Action for PUT /api/v1/openmedia/{id}.
 *
 * [Guessing] The swagger shows Action as an integer but the pasted sections
 * do not include the enum's member values — these ints are a best guess.
 * Confirm against the OpenMediaUpdateRequestModel/Action schema before the
 * first reroute against a real MiCC server: a wrong value here would
 * complete an interaction instead of rerouting it.
 */
enum MitelWorkItemAction: int
{
    case Complete = 0;
    case Reroute = 1;
}
