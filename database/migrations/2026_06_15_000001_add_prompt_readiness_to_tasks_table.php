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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('ai_readiness')->nullable()->after('reviewed_at'); // ready | almost | not_ready
            $table->json('ai_missing')->nullable()->after('ai_readiness');
            $table->longText('ai_prompt')->nullable()->after('ai_missing');
            $table->timestamp('ai_assessed_at')->nullable()->after('ai_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['ai_readiness', 'ai_missing', 'ai_prompt', 'ai_assessed_at']);
        });
    }
};
