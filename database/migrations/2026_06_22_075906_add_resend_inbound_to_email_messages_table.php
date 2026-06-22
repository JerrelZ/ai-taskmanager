<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            // Provider-sourced (e.g. Resend inbound webhook) messages instead of
            // IMAP. `provider` is null for IMAP-synced rows; `provider_email_id`
            // is the provider's own id and the idempotency key for webhook
            // retries (a webhook can be delivered more than once).
            $table->string('provider')->nullable()->after('email_thread_id');
            $table->string('provider_email_id')->nullable()->unique()->after('provider');

            // IMAP-only coordinates: webhook messages have no folder UID, so these
            // become nullable. The (account, folder, uid_validity, uid) unique index
            // still guards IMAP idempotency; webhook idempotency uses provider_email_id.
            $table->unsignedBigInteger('uid_validity')->nullable()->change();
            $table->unsignedBigInteger('uid')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropUnique(['provider_email_id']);
            $table->dropColumn(['provider', 'provider_email_id']);
            $table->unsignedBigInteger('uid_validity')->nullable(false)->change();
            $table->unsignedBigInteger('uid')->nullable(false)->change();
        });
    }
};
