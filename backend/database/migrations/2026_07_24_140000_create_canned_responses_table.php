<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            // null = shown for any language; otherwise ms/en.
            $table->string('language', 5)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_responses');
    }
};
