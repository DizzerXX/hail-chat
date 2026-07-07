<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('faq_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('query_text');
            $table->string('language', 5);
            $table->foreignId('matched_faq_id')->nullable()->constrained('faqs')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('matched_faq_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_queries');
    }
};
