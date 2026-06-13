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
        Schema::table('email_threads', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_read');
            $table->timestamp('snoozed_until')->nullable()->after('archived_at');
            $table->index(['project_id', 'archived_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_threads', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'archived_at']);
            $table->dropColumn(['archived_at', 'snoozed_until']);
        });
    }
};
