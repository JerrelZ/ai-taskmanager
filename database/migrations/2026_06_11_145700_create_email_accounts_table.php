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
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email_address');

            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption')->default('ssl');

            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->string('smtp_encryption')->default('ssl');

            $table->string('username');
            $table->text('password'); // encrypted at the model layer

            $table->text('external_db_dsn')->nullable(); // encrypted JSON at the model layer

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
