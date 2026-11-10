<?php

namespace App\Http\Controllers\DemoShop;

use App\Http\Controllers\Controller;
use App\Services\DemoShop\PurchaseMemoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class PurchaseMemoController extends Controller
{
    public function store(Request $request, PurchaseMemoService $service): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['product_id', 'language', '_token']) !== [], 422);
        $request->validate(['product_id' => 'required|in:TOTE-001', 'language' => 'required|in:en']);
        $key = 'demo-memo-minute:'.hash('sha256', $request->session()->get('demo_owner'));
        if (! RateLimiter::attempt($key, 3, fn () => true, 60)) {
            return response()->json(['error' => 'memo_rate_limited'], 429);
        }
        try {
            $result = $service->generate();
        } catch (Throwable) {
            return response()->json(['error' => 'memo_unavailable', 'message' => 'The memo could not be generated. You can still read the demo notices.'], 503);
        }
        $request->session()->put('demo_memo', ['id' => $result['memo_id'], 'at' => now()->timestamp]);

        return response()->json($result);
    }
}
