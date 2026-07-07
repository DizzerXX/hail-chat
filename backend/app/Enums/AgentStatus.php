<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Away = 'away';
    case Offline = 'offline';
}
