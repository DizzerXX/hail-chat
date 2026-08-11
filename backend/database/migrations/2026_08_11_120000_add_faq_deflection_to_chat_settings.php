<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            // After the pre-chat form, show a "Quick answers" FAQ screen before
            // the visitor proceeds to the live chat. Editable copy is surfaced to
            // the widget via /public/app-config.
            $table->boolean('faq_deflection_enabled')->default(true);
            $table->string('widget_faq_title')->default('Quick answers');
            $table->string('widget_faq_prompt')->default("Didn't find what you were looking for?");
            $table->string('widget_faq_button')->default('Chat with a live agent');
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn([
                'faq_deflection_enabled',
                'widget_faq_title',
                'widget_faq_prompt',
                'widget_faq_button',
            ]);
        });
    }
};
