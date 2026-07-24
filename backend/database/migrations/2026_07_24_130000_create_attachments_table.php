<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            // Unguessable public handle — the download capability, like the
            // conversation uuid. Keys the /public/attachments/{uuid} route.
            $table->uuid('uuid')->unique();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
