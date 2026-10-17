<?php

namespace App\Models;

use Database\Factories\SourceDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_id', 'crawl_run_id', 'canonical_url', 'final_url', 'url_hash', 'content_sha256', 'title', 'published_on', 'fetched_at', 'http_status', 'content_type', 'private_object_key', 'extraction_version'])]
class SourceDocument extends Model
{
    /** @use HasFactory<SourceDocumentFactory> */
    use HasFactory;

    public function source(): BelongsTo
    {
        return $this->belongsTo(CrawlSource::class, 'source_id');
    }

    protected function casts(): array
    {
        return ['published_on' => 'date', 'fetched_at' => 'datetime'];
    }
}
