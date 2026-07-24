<?php

namespace App\Enums;

enum SenderType: string
{
    case Visitor = 'visitor';
    case Agent = 'agent';
    case Bot = 'bot';
    case System = 'system';
    // Agent-only annotation, never shown to the visitor.
    case Note = 'note';
}
