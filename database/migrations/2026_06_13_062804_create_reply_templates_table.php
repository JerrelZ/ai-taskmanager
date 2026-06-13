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
        Schema::create('reply_templates', function (Blueprint $table) {
            $table->id();
            // Null project = available across all projects; otherwise project-scoped.
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reply_templates');
    }
};
