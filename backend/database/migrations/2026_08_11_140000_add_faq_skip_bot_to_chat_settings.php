<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            // When on, proceeding from the FAQ screen connects straight to a human
            // (skips the bot) — the FAQ already served the self-service role.
            $table->boolean('faq_skip_bot')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn('faq_skip_bot');
        });
    }
};
