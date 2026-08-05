<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Single-row store for system-wide identity + policy (branding, data
        // retention). Edited by admins with system.manage; secrets/infra stay
        // in .env and never appear here.
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name')->default('TDEChat');
            $table->text('favicon')->nullable();   // data-URI or empty
            $table->text('logo')->nullable();       // data-URI or empty
            $table->boolean('retention_enabled')->default(false);
            $table->unsignedInteger('retention_days')->default(180);
            $table->timestamp('last_purge_at')->nullable();
            $table->unsignedInteger('last_purge_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
