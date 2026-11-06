<?php

namespace App\Models;

use App\Services\Crawling\OfficialDomainResearch;
use Database\Factories\OfficialLinkCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_document_id', 'url', 'url_hash', 'host', 'anchor_text', 'status', 'error_code', 'fetched_at', 'submitted_by_admin_id', 'last_retried_by_admin_id', 'last_retried_at', 'suggested_by_search_id'])]
class OfficialLinkCandidate extends Model
{
    /** @use HasFactory<OfficialLinkCandidateFactory> */
    use HasFactory;

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class);
    }

    protected static function booted(): void
    {
        static::created(function (self $candidate): void {
            if ($candidate->status === 'pending' && config('domain_research.enabled')) {
                app(OfficialDomainResearch::class)->request($candidate->host);
            }
        });
    }

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime', 'last_retried_at' => 'datetime'];
    }
}
