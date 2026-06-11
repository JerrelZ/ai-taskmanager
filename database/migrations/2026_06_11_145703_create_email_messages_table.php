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
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_folder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_thread_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('uid_validity'); // IMAP UIDVALIDITY epoch the UID belongs to
            $table->unsignedBigInteger('uid'); // IMAP UID within the folder + epoch
            $table->string('message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();

            $table->string('raw_path')->nullable(); // path to the raw RFC822 .eml
            $table->unsignedInteger('raw_size')->nullable();

            $table->string('direction')->default('inbound'); // inbound | outbound
            $table->string('status')->default('received');   // received | parsed | categorised | parse_failed
            $table->unsignedTinyInteger('parse_attempts')->default(0);
            $table->text('parse_error')->nullable();

            // Parsed fields (filled during the parse phase).
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->nullable();
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->json('headers')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            // Hard idempotency guarantee: a folder's UID is unique within a UIDVALIDITY
            // epoch. Including uid_validity lets old + new epochs coexist after a reset
            // without UID-reuse collisions, so re-sync never duplicates and never skips.
            $table->unique(['email_account_id', 'email_folder_id', 'uid_validity', 'uid']);
            $table->index('message_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
