<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership pivot: which workspaces a user may switch into. The user's
     * `workspace_id` stays the *active* workspace; this table records every
     * workspace they belong to.
     */
    public function up(): void
    {
        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        // Every existing user becomes a member of their current workspace.
        foreach (DB::table('users')->whereNotNull('workspace_id')->get(['id', 'workspace_id']) as $user) {
            DB::table('workspace_user')->insertOrIgnore([
                'workspace_id' => $user->workspace_id,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_user');
    }
};
