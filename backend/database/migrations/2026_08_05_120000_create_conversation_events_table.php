<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Agent-facing audit trail of a conversation's handling lifecycle
        // (accepted / transferred / rerouted / abandoned / closed). Deliberately
        // a separate table, not conversation.meta, so it is never exposed on the
        // public widget API. Immutable: rows are appended, never updated.
        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('type');                       // accepted|transferred|rerouted|abandoned|closed
            $table->string('actor_type')->nullable();     // agent|visitor|system
            $table->string('actor_name')->nullable();     // display name captured at event time
            $table->jsonb('detail')->nullable();          // {from, to_team, to_agent, reason, was_with, by}
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_events');
    }
};
