<?php

namespace App\Models;

use Database\Factories\CrawlRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_id', 'job_key', 'status', 'started_at', 'finished_at', 'attempts', 'error_code', 'error_summary'])]
class CrawlRun extends Model
{
    /** @use HasFactory<CrawlRunFactory> */
    use HasFactory;

    public function source(): BelongsTo
    {
        return $this->belongsTo(CrawlSource::class, 'source_id');
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
