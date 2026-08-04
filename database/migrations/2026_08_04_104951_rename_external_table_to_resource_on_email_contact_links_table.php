<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A contact link no longer points at a table and identifier column in a
     * customer database, but at a resource on that project's support API
     * (currently always "users"). Rename the pointer accordingly and drop the
     * identifier column, which carries no meaning over an API.
     */
    public function up(): void
    {
        Schema::table('email_contact_links', function (Blueprint $table) {
            $table->renameColumn('external_table', 'external_resource');
        });

        Schema::table('email_contact_links', function (Blueprint $table) {
            $table->dropColumn('external_id_column');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_contact_links', function (Blueprint $table) {
            $table->renameColumn('external_resource', 'external_table');
        });

        Schema::table('email_contact_links', function (Blueprint $table) {
            $table->string('external_id_column')->default('id')->after('external_table');
        });
    }
};
