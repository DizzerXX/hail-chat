<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Pending = 'pending';
    case Bot = 'bot';
    case AwaitingAcceptance = 'awaiting_acceptance';
    case Active = 'active';
    case Closed = 'closed';
    case Abandoned = 'abandoned';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Closed, self::Abandoned], true);
    }
}
