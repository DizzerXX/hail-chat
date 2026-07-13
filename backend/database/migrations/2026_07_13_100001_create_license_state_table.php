<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('license_state', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id')->unique();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_instances')->nullable();
            $table->jsonb('features')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('metadata')->nullable();
            $table->integer('current_activations')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_valid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_state');
    }
};
