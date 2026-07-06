<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Membership is opt-in: a Member only sees projects they belong to. To keep
     * existing behaviour (Members saw every project in their workspace), attach
     * each Member to every project in the workspaces they belong to. Admins are
     * skipped — they always have access regardless of membership.
     */
    public function up(): void
    {
        $now = now();

        $memberships = DB::table('workspace_user as wu')
            ->join('users as u', 'u.id', '=', 'wu.user_id')
            ->join('projects as p', 'p.workspace_id', '=', 'wu.workspace_id')
            ->where('u.role', 'member')
            ->select('p.id as project_id', 'u.id as user_id')
            ->get();

        $rows = $memberships
            ->map(fn ($row) => [
                'project_id' => $row->project_id,
                'user_id' => $row->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('project_user')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        DB::table('project_user')->truncate();
    }
};
