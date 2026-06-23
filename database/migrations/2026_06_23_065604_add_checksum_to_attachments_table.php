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
            // sha256 of the original bytes, used to reuse an identical file on the
            // same record instead of storing it again (e.g. re-pasting an image).
            $table->string('checksum', 64)->nullable()->after('size')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('checksum');
        });
    }
};
