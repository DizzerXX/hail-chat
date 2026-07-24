<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            // Widget branding — admin-editable, global, surfaced via /public/app-config.
            $table->string('widget_title')->default('Chat with us');
            // Optional (may be blank) — nullable so an empty submission is allowed
            // past the ConvertEmptyStringsToNull middleware.
            $table->string('widget_subtitle')->nullable()->default('TDEChat');
            // Emoji or an image URL; blank = no header icon.
            $table->string('widget_header_icon')->nullable();
            $table->string('widget_launcher_icon')->default('💬');
            $table->string('widget_bot_name')->default('Bot');
            $table->string('widget_name_label')->default('Your name (optional)');
            $table->string('widget_language_label')->default('Language');
            $table->string('widget_message_label')->default('How can we help?');
            $table->string('widget_greeting_placeholder')->default('Taip mesej anda…');
            $table->string('widget_start_label')->default('Start chat');
            $table->string('widget_input_placeholder')->default('Type a message…');
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn([
                'widget_title',
                'widget_subtitle',
                'widget_header_icon',
                'widget_launcher_icon',
                'widget_bot_name',
                'widget_name_label',
                'widget_language_label',
                'widget_message_label',
                'widget_greeting_placeholder',
                'widget_start_label',
                'widget_input_placeholder',
            ]);
        });
    }
};
