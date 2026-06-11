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
        Schema::create('email_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. INBOX, Sent
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('last_seen_uid')->default(0); // sync watermark
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['email_account_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_folders');
    }
};
