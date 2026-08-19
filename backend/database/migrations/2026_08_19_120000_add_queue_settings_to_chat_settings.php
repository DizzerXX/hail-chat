<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            // Show the estimated wait time on the widget's waiting screen, and the
            // bilingual notice shown when no agents are online.
            $table->boolean('wait_time_enabled')->default(true);
            $table->string('no_agents_ms')->default('Tiada ejen tersedia sekarang. Tinggalkan mesej anda dan kami akan menghubungi anda semula.');
            $table->string('no_agents_en')->default("No agents are available right now. Leave your message and we'll get back to you.");
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn(['wait_time_enabled', 'no_agents_ms', 'no_agents_en']);
        });
    }
};
