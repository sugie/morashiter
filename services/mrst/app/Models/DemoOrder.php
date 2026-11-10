<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoOrder extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['owner_hash', 'create_request_id', 'capture_request_id', 'lease_token'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'quantity' => 'integer', 'lease_until' => 'immutable_datetime', 'capture_started_at' => 'immutable_datetime'];
    }
}
