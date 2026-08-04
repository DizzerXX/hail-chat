<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Set when the visitor's page unloads (pagehide beacon); the idle
            // sweep abandons the chat once it's older than the grace period,
            // unless the page reconnects and clears it. Null = visitor present.
            $table->timestamp('visitor_left_at')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('visitor_left_at');
        });
    }
};
