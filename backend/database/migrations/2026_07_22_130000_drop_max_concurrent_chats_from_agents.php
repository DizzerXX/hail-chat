<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent handles one conversation at a time, so a per-agent limit cannot
 * change behaviour. The column was never read by any capacity check.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('max_concurrent_chats');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_concurrent_chats')->default(5);
        });
    }
};
