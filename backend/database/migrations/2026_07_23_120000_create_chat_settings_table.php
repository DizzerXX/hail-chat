<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row store for admin-editable chat settings: idle timers, bubble
 * colour, notification sound, and the bilingual auto-warning text.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('idle_enabled')->default(true);
            $table->unsignedSmallInteger('customer_warn1_minutes')->default(5);
            $table->unsignedSmallInteger('customer_warn2_minutes')->default(8);
            $table->unsignedSmallInteger('customer_close_minutes')->default(10);
            $table->unsignedSmallInteger('agent_idle_minutes')->default(2);

            $table->string('bubble_color', 9)->default('#2f80ed');
            $table->string('notification_sound')->default('notification-1.mp3');

            $table->text('warn1_ms');
            $table->text('warn1_en');
            $table->text('warn2_ms');
            $table->text('warn2_en');
            $table->text('closed_ms');
            $table->text('closed_en');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_settings');
    }
};
