<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DemoOrderFactory extends Factory
{
    public function definition(): array
    {
        return ['owner_hash' => hash('sha256', 'owner-a'), 'client_request_id' => (string) Str::uuid(),
            'product_id' => 'TOTE-001', 'quantity' => 1, 'amount_minor' => 2400, 'currency' => 'USD',
            'catalog_version' => 'aozora-v1', 'create_request_id' => (string) Str::uuid(),
            'capture_request_id' => (string) Str::uuid(), 'status' => 'create_unknown'];
    }

    public function created(): static
    {
        return $this->state(fn () => ['status' => 'created', 'paypal_order_id' => 'DEMO-ORDER-001']);
    }

    public function completed(): static
    {
        return $this->created()->state(fn () => ['status' => 'completed', 'paypal_capture_id' => 'DEMO-CAPTURE-001']);
    }

    public function otherOwner(): static
    {
        return $this->state(fn () => ['owner_hash' => hash('sha256', 'owner-b')]);
    }
}
