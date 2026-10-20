<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'release_id', 'status', 'snapshot_sha256', 'incident_ids', 'data_as_of', 'indexable', 'error',
    'triggered_by_admin_id', 'started_at', 'finished_at',
])]
class SiteRelease extends Model
{
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'triggered_by_admin_id');
    }

    public function archivePath(): string
    {
        return "msgn/releases/{$this->release_id}";
    }

    protected function casts(): array
    {
        return ['incident_ids' => 'array', 'indexable' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
