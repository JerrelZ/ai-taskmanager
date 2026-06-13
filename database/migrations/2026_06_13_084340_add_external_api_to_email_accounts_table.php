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
        Schema::table('email_accounts', function (Blueprint $table) {
            // Optional read-only support API for the project's external system.
            $table->string('external_api_base_url')->nullable()->after('external_db_dsn');
            $table->text('external_api_token')->nullable()->after('external_api_base_url'); // encrypted
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn(['external_api_base_url', 'external_api_token']);
        });
    }
};
