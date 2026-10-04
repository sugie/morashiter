<?php

namespace App\Models;

use Database\Factories\CrawlSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'base_url', 'source_type', 'crawler_code', 'allowed_hosts', 'extraction_config', 'is_enabled'])]
class CrawlSource extends Model
{
    /** @use HasFactory<CrawlSourceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['allowed_hosts' => 'array', 'extraction_config' => 'array', 'is_enabled' => 'boolean'];
    }
}
