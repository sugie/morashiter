<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['display_code', 'origin', 'company_id', 'withdrawn_at', 'withdrawal_reason', 'withdrawn_by_admin_id'])]
class Incident extends Model
{
    /**
     * The company that published it (see incidents:link-companies for older rows).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(IncidentRevision::class);
    }

    /**
     * The latest approved revision; this is what msgn publishes.
     */
    public function currentRevision(): HasOne
    {
        return $this->hasOne(IncidentRevision::class)->ofMany(['revision_number' => 'max'], fn ($query) => $query->where('status', 'approved'));
    }

    public static function nextDisplayCode(): string
    {
        $highest = self::query()->pluck('display_code')
            ->map(fn (string $code): int => (int) preg_replace('/\D/', '', $code))
            ->max() ?? 0;

        return sprintf('INC-%03d', $highest + 1);
    }

    protected function casts(): array
    {
        return ['withdrawn_at' => 'datetime'];
    }
}
