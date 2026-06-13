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
        Schema::create('email_contact_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->string('email');

            // Generic pointer into the project's external database. Each sender may
            // map to a different table (customers, users, companies, ...).
            $table->string('external_table');
            $table->string('external_id_column');
            $table->string('external_id');
            $table->string('label')->nullable(); // cached human-readable name for the UI

            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One link per sender within an inbox.
            $table->unique(['email_account_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_contact_links');
    }
};
