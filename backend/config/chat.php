<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chat settings defaults
    |--------------------------------------------------------------------------
    |
    | Seed values for the single ChatSettings row (admins edit them at runtime
    | on the Chat Settings screen). Idle handling and bubble colour live here.
    |
    */

    'defaults' => [
        'idle_enabled' => true,

        // Customer-idle thresholds, in minutes since the agent's last message.
        'customer_warn1_minutes' => 5,
        'customer_warn2_minutes' => 8,
        'customer_close_minutes' => 10,

        // Agent-idle: minutes an unanswered visitor message may sit before the
        // agent is nudged; the nudge repeats every interval until they reply.
        'agent_idle_minutes' => 2,

        'bubble_color' => '#2f80ed',
        'notification_sound' => 'notification-1.mp3',

        // Auto-sent, visitor-visible warnings (localised by conversation language).
        'warn1_ms' => 'Adakah anda masih di sana? Balas untuk meneruskan perbualan.',
        'warn1_en' => 'Are you still there? Reply to keep the conversation going.',
        'warn2_ms' => 'Kami masih menunggu balasan anda. Perbualan ini akan ditutup jika tiada respons.',
        'warn2_en' => "We're still waiting to hear from you. This chat will close if there's no response.",
        'closed_ms' => 'Perbualan ditutup kerana tidak aktif. Sila mulakan sembang baharu jika anda masih memerlukan bantuan.',
        'closed_en' => 'This conversation was closed due to inactivity. Start a new chat if you still need help.',
    ],

    /*
    | Notification sounds the admin may choose from (played to the agent on an
    | agent-idle nudge). Files live in frontend/public/sounds/ and are supplied
    | by the operator; a missing file simply plays nothing.
    */
    'notification_sounds' => [
        'notification-1.mp3',
        'notification-2.mp3',
        'notification-3.mp3',
        'notification-4.mp3',
        'notification-5.mp3',
        'notification-6.mp3',
        'notification-7.mp3',
        'notification-8.mp3',
        'notification-9.mp3',
    ],

    // Fixed alert played to the agent on a customer-idle warning (not selectable).
    'warning_sound' => 'warning.mp3',

];
