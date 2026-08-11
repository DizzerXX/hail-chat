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

        // Widget branding (admin-editable, global, sent to the widget via
        // /public/app-config). Icons accept an emoji or an image URL.
        'widget_title' => 'Chat with us',
        'widget_subtitle' => 'TDEChat',
        'widget_header_icon' => '',
        'widget_launcher_icon' => '💬',
        'widget_bot_name' => 'Bot',
        'widget_name_label' => 'Your name (optional)',
        'widget_language_label' => 'Language',
        'widget_message_label' => 'How can we help?',
        'widget_greeting_placeholder' => 'Taip mesej anda…',
        'widget_start_label' => 'Start chat',
        'widget_input_placeholder' => 'Type a message…',

        // FAQ deflection: after the pre-chat form, show a "Quick answers" screen
        // with the inbox's FAQs before the visitor proceeds to a live chat.
        // Skipped automatically when there are no FAQs for the chosen language.
        // FAQ chrome is bilingual — it switches with the FAQ language tabs.
        'faq_deflection_enabled' => true,
        'widget_faq_title_ms' => 'Jawapan Pantas',
        'widget_faq_title_en' => 'Quick answers',
        'widget_faq_prompt_ms' => 'Tidak menemui apa yang anda cari?',
        'widget_faq_prompt_en' => "Didn't find what you were looking for?",
        'widget_faq_button_ms' => 'Sembang dengan ejen',
        'widget_faq_button_en' => 'Chat with a live agent',

        // Pre-chat fields. Tier 1: the three first-class fields (name shows by
        // default; collecting email is what makes contact history useful).
        'field_name_enabled' => true,
        'field_name_required' => false,
        'field_email_enabled' => false,
        'field_email_required' => false,
        'field_phone_enabled' => false,
        'field_phone_required' => false,
        'widget_email_label' => 'Email',
        'widget_phone_label' => 'Phone',
        // Tier 2: custom fields, e.g. [{key,label,type,required,options}].
        'custom_fields' => [],

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
        'notification-9.wav',
    ],

    // Fixed alert played to the agent on a customer-idle warning (not selectable).
    'warning_sound' => 'warning.mp3',

    // Grace after the visitor's page unloads (pagehide) before the idle sweep
    // abandons the chat as `visitor_left`. A refresh reconnects and clears the
    // flag well within this window. The sweep runs once a minute, so the real
    // end lands between this value and ~60s later.
    'visitor_left_grace_seconds' => (int) env('CHAT_VISITOR_LEFT_GRACE', 30),

];
