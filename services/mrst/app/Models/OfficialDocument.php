<?php

namespace App\Models;

use Database\Factories\OfficialDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'official_domain_id', 'link_candidate_id', 'url', 'url_hash', 'final_url', 'content_sha256',
    'content_type', 'private_object_key', 'title', 'http_status', 'fetched_at',
])]
class OfficialDocument extends Model
{
    /** @use HasFactory<OfficialDocumentFactory> */
    use HasFactory;

    public function officialDomain(): BelongsTo
    {
        return $this->belongsTo(OfficialDomain::class);
    }

    public function linkCandidate(): BelongsTo
    {
        return $this->belongsTo(OfficialLinkCandidate::class, 'link_candidate_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(IncidentReview::class);
    }

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }
}
