<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case ConversationStarted = 'conversation.started';
    case ConversationClosed = 'conversation.closed';
    case ConversationEscalated = 'conversation.escalated';
    case FaqMissed = 'faq.missed';
}
