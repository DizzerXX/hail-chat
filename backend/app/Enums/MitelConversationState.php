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

    /**
     * The swagger documents an integer enum, but live MCX servers serialize
     * the member NAME (e.g. "Acd") — accept both.
     */
    public static function fromResponse(mixed $value): self
    {
        if (is_numeric($value)) {
            return self::tryFrom((int) $value) ?? self::Unknown;
        }

        if (is_string($value)) {
            foreach (self::cases() as $case) {
                if (strcasecmp($case->name, $value) === 0) {
                    return $case;
                }
            }
        }

        return self::Unknown;
    }
}
