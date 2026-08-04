<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the stored credentials for a direct connection to a project's
     * customer database. External access now runs exclusively through that
     * project's read-only support API, so there is no DSN left to keep — and no
     * database password at rest that could leak.
     */
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn('external_db_dsn');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->text('external_db_dsn')->nullable()->after('password');
        });
    }
};
