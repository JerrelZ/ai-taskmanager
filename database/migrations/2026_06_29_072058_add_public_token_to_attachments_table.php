<?php

use App\Models\Attachment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('public_token', 64)->nullable()->after('checksum');
        });

        // Backfill existing rows with an unguessable token so their public
        // share links resolve.
        Attachment::query()->whereNull('public_token')->each(function (Attachment $attachment): void {
            $attachment->forceFill(['public_token' => Str::random(40)])->saveQuietly();
        });

        Schema::table('attachments', function (Blueprint $table): void {
            $table->unique('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
