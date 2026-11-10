<?php

namespace App\Services\DemoShop;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayPalClient
{
    private const API = 'https://api-m.sandbox.paypal.com';

    public function ready(): bool
    {
        return config('demo_shop.paypal.mode') === 'sandbox'
            && (bool) config('demo_shop.paypal.client_id') && (bool) config('demo_shop.paypal.secret')
            && (bool) config('demo_shop.paypal.merchant_id');
    }

    public function call(string $method, string $path, array $body = [], ?string $key = null): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('sandbox_unavailable');
        }
        $cacheKey = 'demo-paypal-token:'.hash('sha256', config('demo_shop.paypal.client_id').config('demo_shop.paypal.secret'));
        $token = Cache::get($cacheKey);
        if (! $token) {
            $response = Http::asForm()->acceptJson()->withBasicAuth(config('demo_shop.paypal.client_id'), config('demo_shop.paypal.secret'))
                ->connectTimeout(5)->timeout(10)->withoutRedirecting()->post(self::API.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new RuntimeException('sandbox_unavailable');
            }
            Cache::put($cacheKey, $token, max(1, min(300, (int) $response->json('expires_in', 60) - 30)));
        }
        $request = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(15)->withoutRedirecting();
        if ($key) {
            $request = $request->withHeaders(['PayPal-Request-Id' => $key, 'Prefer' => 'return=representation']);
        }
        $response = $method === 'GET' ? $request->get(self::API.$path)
            : $request->withBody(json_encode((object) $body, JSON_THROW_ON_ERROR), 'application/json')->post(self::API.$path);
        if ($response->status() === 401) {
            Cache::forget($cacheKey);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('sandbox_unavailable');
        }

        return $response->json();
    }
}
