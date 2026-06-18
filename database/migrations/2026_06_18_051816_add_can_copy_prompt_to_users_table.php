<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_copy_prompt')->default(false)->after('role');
        });

        // Grant the AI-prompt copy feature to the maintainer by default.
        DB::table('users')->where('email', 'jerrel@zendos.nl')->update(['can_copy_prompt' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_copy_prompt');
        });
    }
};
