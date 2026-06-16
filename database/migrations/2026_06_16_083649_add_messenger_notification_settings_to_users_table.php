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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('messenger_notifications_enabled')->default(true)->after('client_id');
            $table->string('messenger_notification_mode')->default('realtime')->after('messenger_notifications_enabled');
            $table->unsignedSmallInteger('messenger_digest_interval_hours')->default(4)->after('messenger_notification_mode');
            $table->timestamp('messenger_digest_last_sent_at')->nullable()->after('messenger_digest_interval_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'messenger_notifications_enabled',
                'messenger_notification_mode',
                'messenger_digest_interval_hours',
                'messenger_digest_last_sent_at',
            ]);
        });
    }
};
