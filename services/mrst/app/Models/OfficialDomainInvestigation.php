<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'host', 'generation', 'status', 'recommendation', 'organization_name', 'evidence', 'raw_proposal',
    'reason', 'error_code', 'final_url', 'redirect_url', 'snapshot_path', 'pages', 'provider', 'model',
    'rule_version', 'prompt_version', 'attempts', 'started_at', 'completed_at',
])]
class OfficialDomainInvestigation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'evidence' => 'array', 'raw_proposal' => 'array', 'pages' => 'array',
            'attempts' => 'integer', 'generation' => 'integer',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }
}
