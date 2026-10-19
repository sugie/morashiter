<?php

namespace App\Models;

use Database\Factories\OfficialLinkCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_document_id', 'url', 'url_hash', 'host', 'anchor_text', 'status'])]
class OfficialLinkCandidate extends Model
{
    /** @use HasFactory<OfficialLinkCandidateFactory> */
    use HasFactory;

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class);
    }
}
