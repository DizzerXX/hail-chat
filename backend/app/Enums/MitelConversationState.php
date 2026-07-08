<?php

namespace App\Enums;

/**
 * MiCC Open Media ConversationState (integer enum per the MiCC SDK swagger).
 */
enum MitelConversationState: int
{
    case Unknown = 0;
    case Offered = 1;
    case Held = 2;
    case Acd = 3;
    case NonAcd = 4;
    case WaitingResponse = 5;
    case Ended = 6;
    case Abandoned = 7;
    case ExitedQueue = 8;
    case Queued = 9;
    case Draft = 10;
    case InboxFailed = 11;
    case InboxFailedReply = 12;
    case WorkTimer = 13;

    public static function fromResponse(mixed $value): self
    {
        return is_numeric($value) ? (self::tryFrom((int) $value) ?? self::Unknown) : self::Unknown;
    }
}
