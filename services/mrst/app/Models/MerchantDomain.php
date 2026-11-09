<?php

namespace App\Models;

use Database\Factories\MerchantDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shopping-site host confirmed by an administrator. Disabled rows are kept (disabled_at) and never
 * reach the public index.
 */
#[Fillable(['host', 'registrable_domain', 'company_id', 'evidence_url', 'note', 'verified_by_admin_id', 'verified_at', 'disabled_at'])]
class MerchantDomain extends Model
{
    /** @use HasFactory<MerchantDomainFactory> */
    use HasFactory;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'verified_by_admin_id');
    }

    /**
     * @param  Builder<MerchantDomain>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'disabled_at' => 'datetime'];
    }
}
