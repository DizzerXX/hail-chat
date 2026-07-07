<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->uuid('translation_group');
            $table->string('language', 5);
            $table->string('question', 500);
            $table->text('answer');
            $table->jsonb('keywords')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->unique(['translation_group', 'language']);
            $table->index(['language', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
