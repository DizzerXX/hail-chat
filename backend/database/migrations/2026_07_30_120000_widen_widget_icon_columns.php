<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    // Icons may now be an uploaded image stored inline as a data-URI, which is
    // far longer than a varchar(255) — widen to text.
    public function up(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->text('widget_header_icon')->nullable()->change();
            $table->text('widget_launcher_icon')->default('💬')->change();
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->string('widget_header_icon')->nullable()->change();
            $table->string('widget_launcher_icon')->default('💬')->change();
        });
    }
};
