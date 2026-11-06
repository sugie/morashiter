<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'official_link_candidate_id', 'status', 'proposals', 'rule_candidates', 'pages_fetched', 'analyzer', 'error_code',
    'requested_by_admin_id', 'dismissed_by_admin_id', 'dismiss_reason', 'started_at', 'completed_at',
])]
class OfficialAnnouncementSearch extends Model
{
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(OfficialLinkCandidate::class, 'official_link_candidate_id');
    }

    /**
     * Every URL the admin may add from this search: AI proposals and rule candidates.
     *
     * @return list<string>
     */
    public function suggestedUrls(): array
    {
        return array_values(array_unique(array_merge(
            array_column($this->proposals ?? [], 'url'),
            array_column($this->rule_candidates ?? [], 'url'),
        )));
    }

    protected function casts(): array
    {
        return [
            'proposals' => 'array', 'rule_candidates' => 'array', 'pages_fetched' => 'integer',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }
}
