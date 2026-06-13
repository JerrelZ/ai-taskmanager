<?php

namespace App\Models;

use Database\Factories\ReplyTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable reply snippet. A null project makes it available everywhere;
 * otherwise it is scoped to a single project.
 *
 * @property int $id
 * @property int|null $project_id
 * @property string $name
 * @property string $body
 * @property int|null $created_by
 */
class ReplyTemplate extends Model
{
    /** @use HasFactory<ReplyTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'body',
        'created_by',
    ];

    /**
     * Templates available to a project: its own plus the global ones.
     *
     * @param  Builder<ReplyTemplate>  $query
     */
    public function scopeForProject(Builder $query, int $projectId): void
    {
        $query->where(function (Builder $q) use ($projectId): void {
            $q->whereNull('project_id')->orWhere('project_id', $projectId);
        });
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
