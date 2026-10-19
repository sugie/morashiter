<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'official_document_id', 'status', 'analyzer', 'analysis', 'error_code', 'analyzed_at',
    'reviewed_by_admin_id', 'reviewed_at',
])]
class IncidentReview extends Model
{
    public function officialDocument(): BelongsTo
    {
        return $this->belongsTo(OfficialDocument::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_id');
    }

    protected function casts(): array
    {
        return ['analysis' => 'array', 'analyzed_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
