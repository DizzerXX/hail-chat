<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Licenses are a pool synced from the platform, one spent per agent — not a
 * single per-installation key. The old single-row license_state modelled the
 * latter and is dropped; no installation ever activated, so there is nothing
 * to migrate.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('license_state');

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();

            // Mirrors the platform record.
            $table->unsignedBigInteger('platform_license_id')->unique();
            $table->string('license_key')->unique();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
            $table->string('customer_company')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activation_date')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_instances')->nullable();
            $table->jsonb('features')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            // Local allocation: one license activates one agent.
            $table->foreignId('assigned_agent_id')->nullable()->unique()
                ->constrained('agents')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        // license_state is not recreated: its model and the instance-activation
        // flow it served are gone.
        Schema::dropIfExists('licenses');
    }
};
