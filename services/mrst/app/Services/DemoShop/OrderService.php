<?php

namespace App\Services\DemoShop;

use App\Models\DemoOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OrderService
{
    public function __construct(private readonly Catalog $catalog, private readonly PayPalClient $paypal) {}

    public function create(string $owner, array $input): DemoOrder
    {
        abort_unless($this->paypal->ready(), 503, 'Sandbox checkout is not configured.');
        $product = $this->catalog->product();
        $order = DemoOrder::query()->createOrFirst(['owner_hash' => $owner, 'client_request_id' => $input['client_request_id']], [
            'product_id' => $product['id'], 'quantity' => 1, 'amount_minor' => $product['amount_minor'],
            'currency' => $product['currency'], 'catalog_version' => $this->catalog->data()['store']['catalog_version'],
            'memo_id' => $input['memo_id'] ?? null, 'create_request_id' => (string) Str::uuid(),
            'capture_request_id' => (string) Str::uuid(), 'status' => 'create_unknown',
        ]);
        abort_unless($order->product_id === $input['product_id'] && $order->quantity === (int) $input['quantity'], 409);
        if ($order->paypal_order_id) {
            return $order;
        }
        // Conservative boundary within the Orders API's default six-hour key retention.
        abort_if($order->created_at->lt(now()->subHours(5)), 409, 'Order outcome requires manual review.');
        $token = $this->claim($order, 'creating');
        try {
            $remote = $this->paypal->call('POST', '/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'custom_id' => $order->id, 'description' => 'Aozora Goods fictional demo: Everyday Canvas Tote',
                    'payee' => ['merchant_id' => config('demo_shop.paypal.merchant_id')],
                    'amount' => ['currency_code' => $order->currency, 'value' => $this->amount($order)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => ['shipping_preference' => 'NO_SHIPPING']]],
            ], $order->create_request_id);
            if (! is_string($remote['id'] ?? null) || ! preg_match('/^[A-Za-z0-9-]{1,64}$/D', $remote['id'])) {
                throw new \RuntimeException('invalid_paypal_order');
            }
        } catch (Throwable) {
            return $this->finish($order, $token, ['status' => 'create_unknown', 'error_code' => 'create_outcome_unknown']);
        }

        return $this->finish($order, $token, ['status' => 'created', 'paypal_order_id' => $remote['id'], 'error_code' => null]);
    }

    public function capture(DemoOrder $order, bool $reconcile = false): DemoOrder
    {
        if (in_array($order->status, ['completed', 'failed'], true)) {
            return $order;
        }
        abort_unless($order->paypal_order_id, 409, 'Retry the original order request first.');
        $token = $this->claim($order, 'capturing');
        try {
            $remote = $this->paypal->call('GET', '/v2/checkout/orders/'.rawurlencode($order->paypal_order_id));
        } catch (Throwable) {
            return $this->finish($order, $token, ['status' => 'capture_unknown', 'error_code' => 'lookup_unavailable']);
        }
        if (! $this->matches($order, $remote)) {
            $this->finish($order, $token, ['status' => 'capture_unknown', 'error_code' => 'order_mismatch']);
            abort(409, 'The PayPal order could not be verified.');
        }
        if (isset($remote['purchase_units'][0]['payments']['captures'])) {
            return $this->settle($order, $token, $remote);
        }
        if ($reconcile || ($remote['status'] ?? null) !== 'APPROVED') {
            $uncertain = $order->capture_started_at !== null || in_array($order->status, ['pending', 'capture_unknown'], true);
            $order = $this->finish($order, $token, ['status' => $uncertain ? 'capture_unknown' : 'created',
                'error_code' => $uncertain ? 'capture_outcome_unknown' : null]);
            abort_unless($reconcile, 409, 'Payment has not been approved.');

            return $order;
        }
        if ($order->capture_started_at?->lt(now()->subHours(5))) {
            $this->finish($order, $token, ['status' => 'capture_unknown', 'error_code' => 'manual_review_required']);
            abort(409, 'Payment outcome requires manual review.');
        }
        DemoOrder::query()->whereKey($order->id)->where('lease_token', $token)->whereNull('capture_started_at')
            ->update(['capture_started_at' => now()]);
        try {
            $remote = $this->paypal->call('POST', '/v2/checkout/orders/'.rawurlencode($order->paypal_order_id).'/capture', [], $order->capture_request_id);
        } catch (Throwable) {
            return $this->finish($order, $token, ['status' => 'capture_unknown', 'error_code' => 'capture_outcome_unknown']);
        }

        return $this->settle($order, $token, $remote);
    }

    private function matches(DemoOrder $order, array $remote): bool
    {
        $units = $remote['purchase_units'] ?? [];

        return ($remote['id'] ?? null) === $order->paypal_order_id && ($remote['intent'] ?? null) === 'CAPTURE'
            && count($units) === 1 && ($units[0]['custom_id'] ?? null) === $order->id
            && ($units[0]['payee']['merchant_id'] ?? null) === config('demo_shop.paypal.merchant_id')
            && ($units[0]['amount']['currency_code'] ?? null) === $order->currency
            && ($units[0]['amount']['value'] ?? null) === $this->amount($order);
    }

    private function settle(DemoOrder $order, string $token, array $remote): DemoOrder
    {
        $units = $remote['purchase_units'] ?? [];
        $captures = $units[0]['payments']['captures'] ?? [];
        $capture = $captures[0] ?? [];
        if (($remote['id'] ?? null) !== $order->paypal_order_id || count($units) !== 1 || count($captures) !== 1
            || ! is_string($capture['id'] ?? null) || $capture['id'] === ''
            || ($capture['amount']['currency_code'] ?? null) !== $order->currency
            || ($capture['amount']['value'] ?? null) !== $this->amount($order)) {
            return $this->finish($order, $token, ['status' => 'capture_unknown', 'error_code' => 'capture_mismatch']);
        }
        $status = match ($capture['status'] ?? null) {
            'COMPLETED' => 'completed', 'PENDING' => 'pending', 'DECLINED', 'FAILED' => 'failed', default => 'capture_unknown',
        };

        return $this->finish($order, $token, ['status' => $status, 'paypal_capture_id' => $capture['id'], 'error_code' => null]);
    }

    private function claim(DemoOrder $order, string $status): string
    {
        return DB::transaction(function () use ($order, $status): string {
            $current = DemoOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_if($current->status === 'completed' || $current->lease_until?->isFuture(), 409, 'This order is already being processed.');
            $token = (string) Str::uuid();
            $current->update(['lease_token' => $token, 'lease_until' => now()->addSeconds(120), 'status' => $status]);

            return $token;
        });
    }

    private function finish(DemoOrder $order, string $token, array $changes): DemoOrder
    {
        $updated = DemoOrder::query()->whereKey($order->id)->where('lease_token', $token)
            ->update($changes + ['lease_token' => null, 'lease_until' => null]);
        abort_unless($updated === 1, 409, 'The order is being reconciled.');
        $fresh = $order->fresh();
        $fresh->wasRecentlyCreated = $order->wasRecentlyCreated;

        return $fresh;
    }

    public function amount(DemoOrder $order): string
    {
        return sprintf('%d.%02d', intdiv($order->amount_minor, 100), $order->amount_minor % 100);
    }
}
