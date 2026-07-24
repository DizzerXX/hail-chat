<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('translation_cache', function (Blueprint $table) {
            $table->id();
            // sha256 of "{source}:{target}:{text}"
            $table->string('hash', 64)->unique();
            $table->string('source', 5);
            $table->string('target', 5);
            $table->text('text');
            $table->text('translation');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_cache');
    }
};
