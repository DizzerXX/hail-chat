<?php

use App\Models\Inbox;
use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['inbox_id', 'team_id']);
        });

        // An inbox with no team is invisible to every agent, so existing
        // installs are backfilled with every team: visibility is unchanged on
        // deploy, and admins narrow it afterwards.
        $teams = Team::query()->pluck('id');

        if ($teams->isNotEmpty()) {
            Inbox::query()->each(fn (Inbox $inbox) => $inbox->teams()->sync($teams));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_team');
    }
};
