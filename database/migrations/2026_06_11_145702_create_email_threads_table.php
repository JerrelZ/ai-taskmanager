<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->string('thread_key'); // root Message-ID or normalised-subject hash
            $table->string('ai_category')->nullable();
            $table->text('ai_summary')->nullable();
            $table->timestamp('ai_categorised_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->unique(['email_account_id', 'thread_key']);
            $table->index(['project_id', 'ai_category', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_threads');
    }
};
