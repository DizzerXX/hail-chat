<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('priority')->default(0);
            $table->jsonb('conditions')->default(DB::raw("'{}'::jsonb"));
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['inbox_id', 'is_active', 'priority']);
        });

        // A rule must target exactly one of: a team or an agent.
        DB::statement(
            'alter table routing_rules add constraint routing_rules_single_target_check '.
            'check (num_nonnulls(team_id, agent_id) = 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_rules');
    }
};
