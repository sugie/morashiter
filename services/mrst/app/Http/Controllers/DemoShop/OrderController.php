<?php

namespace App\Http\Controllers\DemoShop;

use App\Http\Controllers\Controller;
use App\Models\DemoOrder;
use App\Services\DemoShop\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function store(Request $request, OrderService $service): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['product_id', 'quantity', 'client_request_id', 'memo_id', '_token']) !== [], 422);
        $input = $request->validate(['product_id' => 'required|in:TOTE-001', 'quantity' => 'required|integer|in:1',
            'client_request_id' => 'required|uuid', 'memo_id' => 'nullable|uuid']);
        if (isset($input['memo_id'])) {
            abort_unless($request->session()->get('demo_memo.id') === $input['memo_id']
                && $request->session()->get('demo_memo.at', 0) > now()->subDay()->timestamp, 422);
        }
        $order = $service->create($this->owner($request), $input);

        return $this->result($order, $service, $order->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $order, OrderService $service): JsonResponse
    {
        return $this->result($this->owned($request, $order), $service);
    }

    public function capture(Request $request, string $order, OrderService $service): JsonResponse
    {
        return $this->result($service->capture($this->owned($request, $order)), $service);
    }

    public function reconcile(Request $request, string $order, OrderService $service): JsonResponse
    {
        return $this->result($service->capture($this->owned($request, $order), reconcile: true), $service);
    }

    private function owned(Request $request, string $id): DemoOrder
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/D', $id), 404);

        return DemoOrder::query()->whereKey($id)->where('owner_hash', $this->owner($request))->firstOrFail();
    }

    private function owner(Request $request): string
    {
        return hash('sha256', $request->session()->get('demo_owner'));
    }

    private function result(DemoOrder $order, OrderService $service, int $status = 200): JsonResponse
    {
        if (in_array($order->status, ['create_unknown', 'capture_unknown', 'pending'], true)) {
            $status = 202;
        }

        return response()->json(['id' => $order->id, 'status' => $order->status, 'amount' => $service->amount($order),
            'currency' => $order->currency, 'paypal_order_id' => $order->paypal_order_id,
            'paypal_capture_id' => $order->paypal_capture_id, 'error_code' => $order->error_code], $status);
    }
}
