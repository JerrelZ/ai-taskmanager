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
        Schema::table('attachments', function (Blueprint $table) {
            // A file uploaded inside a comment stays attached to the task (so it
            // shows up among all attachments) but also references the comment it
            // was posted in. Nulled when that comment is removed so the file
            // simply remains a plain task attachment.
            $table->foreignId('comment_id')->nullable()->after('attachable_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comment_id');
        });
    }
};
