<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The FAQ screen switches language with its tabs, so its chrome (title,
        // prompt, button) needs a Malay and an English value each — replacing the
        // single-string columns added moments earlier in this same branch.
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->string('widget_faq_title_ms')->default('Jawapan Pantas');
            $table->string('widget_faq_title_en')->default('Quick answers');
            $table->string('widget_faq_prompt_ms')->default('Tidak menemui apa yang anda cari?');
            $table->string('widget_faq_prompt_en')->default("Didn't find what you were looking for?");
            $table->string('widget_faq_button_ms')->default('Sembang dengan ejen');
            $table->string('widget_faq_button_en')->default('Chat with a live agent');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn(['widget_faq_title', 'widget_faq_prompt', 'widget_faq_button']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->string('widget_faq_title')->default('Quick answers');
            $table->string('widget_faq_prompt')->default("Didn't find what you were looking for?");
            $table->string('widget_faq_button')->default('Chat with a live agent');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn([
                'widget_faq_title_ms', 'widget_faq_title_en',
                'widget_faq_prompt_ms', 'widget_faq_prompt_en',
                'widget_faq_button_ms', 'widget_faq_button_en',
            ]);
        });
    }
};
