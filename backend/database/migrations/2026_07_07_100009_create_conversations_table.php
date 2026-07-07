<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('inbox_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->string('language', 5)->default('ms');
            $table->string('visitor_name')->nullable();
            $table->string('visitor_email')->nullable();
            $table->string('visitor_phone', 50)->nullable();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_work_item_id')->nullable()->unique();
            $table->string('external_state')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('last_activity_at')->useCurrent();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['inbox_id', 'status']);
            $table->index(['assigned_agent_id', 'status']);
            $table->index(['status', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
