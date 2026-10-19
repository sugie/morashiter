<?php

namespace App\Models;

use Database\Factories\OfficialDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'host', 'verified_by_admin_id', 'verified_at', 'note'])]
class OfficialDomain extends Model
{
    /** @use HasFactory<OfficialDomainFactory> */
    use HasFactory;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'verified_by_admin_id');
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
