<?php

namespace App\Models;

use Database\Factories\CrawlItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'crawl_run_id', 'source_id', 'normalized_url', 'url_hash', 'status', 'attempts',
    'next_attempt_at', 'leased_until', 'lease_token', 'source_document_id',
    'error_code', 'discovered_at', 'finished_at',
])]
class CrawlItem extends Model
{
    /** @use HasFactory<CrawlItemFactory> */
    use HasFactory;

    public function source(): BelongsTo
    {
        return $this->belongsTo(CrawlSource::class, 'source_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class, 'crawl_run_id');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class, 'source_document_id');
    }

    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'leased_until'    => 'datetime',
            'discovered_at'   => 'datetime',
            'finished_at'     => 'datetime',
        ];
    }
}
