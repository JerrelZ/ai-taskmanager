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
            // Original Linear identifier (e.g. "REVBOOS-10") for tickets imported
            // from a Linear export. Null for tickets created in this app. Its
            // presence marks a ticket as Linear-sourced so we can flag it in the
            // UI and look the issue back up in Linear (e.g. for its comments).
            $table->string('linear_id')->nullable()->after('number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('linear_id');
        });
    }
};
