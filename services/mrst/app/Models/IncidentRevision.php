<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'incident_id', 'revision_number', 'status', 'approval_method', 'approved_by_admin_id', 'approved_at', 'incident_review_id',
    'name', 'company', 'type', 'scale', 'brief', 'date', 'incident_status', 'disclosure', 'report', 'quantity',
    'data_type', 'summary', 'target', 'action', 'response', 'source', 'contact',
])]
class IncidentRevision extends Model
{
    /**
     * Columns that hold the site's Incident fields, keyed by their site (morashiter-data.ts) name.
     */
    public const PUBLIC_FIELDS = [
        'name' => 'name', 'company' => 'company', 'type' => 'type', 'scale' => 'scale', 'brief' => 'brief',
        'date' => 'date', 'status' => 'incident_status', 'disclosure' => 'disclosure', 'report' => 'report',
        'quantity' => 'quantity', 'dataType' => 'data_type', 'summary' => 'summary', 'target' => 'target',
        'action' => 'action', 'response' => 'response', 'source' => 'source', 'contact' => 'contact',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(IncidentReview::class, 'incident_review_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_admin_id');
    }

    /**
     * The record in the static site's Incident shape (same keys and order as launch-incidents.json).
     *
     * @return array<string, string|null>
     */
    public function toPublicArray(): array
    {
        $record = ['id' => $this->incident->display_code];
        foreach (['name', 'company', 'type', 'scale', 'brief', 'date', 'status', 'disclosure', 'report', 'quantity', 'dataType', 'summary', 'target', 'action', 'response', 'source', 'contact'] as $key) {
            $record[$key] = $this->{self::PUBLIC_FIELDS[$key]};
        }

        return $record;
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'revision_number' => 'integer'];
    }
}
