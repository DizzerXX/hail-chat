<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            // Tier 1 — the three first-class fields (real columns; drive contact
            // history + email validation). Each is show/required-configurable.
            $table->boolean('field_name_enabled')->default(true);
            $table->boolean('field_name_required')->default(false);
            $table->boolean('field_email_enabled')->default(false);
            $table->boolean('field_email_required')->default(false);
            $table->boolean('field_phone_enabled')->default(false);
            $table->boolean('field_phone_required')->default(false);
            // Labels for the email/phone fields (name reuses widget_name_label).
            $table->string('widget_email_label')->default('Email');
            $table->string('widget_phone_label')->default('Phone');

            // Tier 2 — arbitrary custom fields: [{key,label,type,required,options}].
            // Answers are stored in conversation.meta.fields.
            $table->jsonb('custom_fields')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn([
                'field_name_enabled',
                'field_name_required',
                'field_email_enabled',
                'field_email_required',
                'field_phone_enabled',
                'field_phone_required',
                'widget_email_label',
                'widget_phone_label',
                'custom_fields',
            ]);
        });
    }
};
