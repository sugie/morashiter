<?php

namespace Tests\Feature;

use App\Models\DemoOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemoOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = 'http://aozora-zakka.localhost/demo-shop/orders';

    private const API = 'https://api-m.sandbox.paypal.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set(['demo_shop.enabled' => true, 'demo_shop.paypal.client_id' => 'test-client',
            'demo_shop.paypal.secret' => 'test-secret', 'demo_shop.paypal.merchant_id' => 'DEMO-MERCHANT']);
        $this->withSession(['demo_owner' => 'owner-a']);
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/demo-shop/scenario.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function paypal(array $overrides = []): void
    {
        $f = $this->fixture();
        Http::fake($overrides + [
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 300]),
            self::API.'/v2/checkout/orders' => Http::response($f['paypal']['created'], 201),
            self::API.'/v2/checkout/orders/DEMO-ORDER-001' => function () use ($f) {
                $body = $f['paypal']['approved'];
                $body['purchase_units'][0]['custom_id'] = DemoOrder::query()->sole()->id;

                return Http::response($body);
            },
            self::API.'/v2/checkout/orders/DEMO-ORDER-001/capture' => function () use ($f) {
                $body = $f['paypal']['completed'];
                $body['purchase_units'][0]['custom_id'] = DemoOrder::query()->sole()->id;

                return Http::response($body, 201);
            },
        ]);
    }

    public function test_create_capture_and_retries_return_one_completed_order(): void
    {
        $this->paypal();
        $payload = $this->fixture()['requests']['create'];
        $order = $this->postJson(self::URL, $payload)->assertCreated()->json();
        $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('id', $order['id']);
        $this->postJson(self::URL.'/'.$order['id'].'/capture')->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('amount', '24.00');
        $this->postJson(self::URL.'/'.$order['id'].'/capture')->assertOk()->assertJsonPath('status', 'completed');
        $this->assertDatabaseCount('demo_orders', 1);
        $this->assertDatabaseHas('demo_orders', ['status' => 'completed', 'paypal_capture_id' => 'DEMO-CAPTURE-001']);
        Http::assertSentCount(4);
        Http::assertSent(fn ($r) => $r->url() === self::API.'/v2/checkout/orders'
            && $r['purchase_units'][0]['amount']['value'] === '24.00' && $r->hasHeader('PayPal-Request-Id'));
        $this->assertNotSame(DemoOrder::query()->sole()->create_request_id, DemoOrder::query()->sole()->capture_request_id);
    }

    #[DataProvider('badInputs')]
    public function test_rejects_invalid_order_input_before_payment(array $changes): void
    {
        $this->postJson(self::URL, array_replace($this->fixture()['requests']['create'], $changes))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public static function badInputs(): array
    {
        return ['zero' => [['quantity' => 0]], 'two' => [['quantity' => 2]], 'unknown' => [['product_id' => 'UNKNOWN']],
            'price' => [['amount' => '0.01']], 'bad-key' => [['client_request_id' => 'bad']]];
    }

    public function test_another_session_cannot_read_or_capture_an_order(): void
    {
        $this->paypal();
        $id = $this->postJson(self::URL, $this->fixture()['requests']['create'])->assertCreated()->json('id');
        $this->withSession(['demo_owner' => 'owner-b']);
        $this->getJson(self::URL.'/'.$id)->assertNotFound();
        $this->postJson(self::URL.'/'.$id.'/capture')->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_capture_timeout_is_reconciled_without_another_charge(): void
    {
        $lookups = 0;
        $this->paypal([
            self::API.'/v2/checkout/orders/DEMO-ORDER-001/capture' => Http::failedConnection(),
            self::API.'/v2/checkout/orders/DEMO-ORDER-001' => function () use (&$lookups) {
                $body = $this->fixture()['paypal'][++$lookups === 1 ? 'approved' : 'completed'];
                $body['intent'] = 'CAPTURE';
                $body['purchase_units'][0]['custom_id'] = DemoOrder::query()->sole()->id;
                $body['purchase_units'][0]['amount'] = ['currency_code' => 'USD', 'value' => '24.00'];

                return Http::response($body);
            },
        ]);
        $id = $this->postJson(self::URL, $this->fixture()['requests']['create'])->assertCreated()->json('id');
        $this->postJson(self::URL.'/'.$id.'/capture')->assertStatus(202)->assertJsonPath('status', 'capture_unknown');
        $this->postJson(self::URL.'/'.$id.'/reconcile')->assertOk()->assertJsonPath('status', 'completed');
        Http::assertSentCount(5);
    }

    public function test_capture_request_uses_an_empty_json_object(): void
    {
        $this->paypal();
        $id = $this->postJson(self::URL, $this->fixture()['requests']['create'])->assertCreated()->json('id');
        $this->postJson(self::URL.'/'.$id.'/capture')->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/capture') && $r->body() === '{}');
    }

    public function test_busy_order_cannot_start_a_second_capture(): void
    {
        $order = DemoOrder::factory()->created()->create(['lease_token' => fake()->uuid(), 'lease_until' => now()->addMinute()]);
        $this->postJson(self::URL.'/'.$order->id.'/capture')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_unknown_capture_remains_unknown_if_lookup_has_no_final_result(): void
    {
        $order = DemoOrder::factory()->created()->create(['status' => 'capture_unknown', 'capture_started_at' => now()]);
        $this->paypal();
        $this->postJson(self::URL.'/'.$order->id.'/reconcile')->assertStatus(202)->assertJsonPath('status', 'capture_unknown');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/capture'));
    }

    public function test_amount_mismatch_never_captures(): void
    {
        $body = $this->fixture()['paypal']['approved'];
        $body['purchase_units'][0]['amount']['value'] = '0.01';
        $this->paypal([self::API.'/v2/checkout/orders/DEMO-ORDER-001' => Http::response($body)]);
        $id = $this->postJson(self::URL, $this->fixture()['requests']['create'])->assertCreated()->json('id');
        $this->postJson(self::URL.'/'.$id.'/capture')->assertStatus(409);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/capture'));
    }

    #[DataProvider('mismatchedOrders')]
    public function test_unverified_orders_never_capture(string $field, mixed $value): void
    {
        $order = DemoOrder::factory()->created()->create();
        $body = $this->fixture()['paypal']['approved'];
        $body['purchase_units'][0]['custom_id'] = $order->id;
        data_set($body, $field, $value);
        $this->paypal([self::API.'/v2/checkout/orders/DEMO-ORDER-001' => Http::response($body)]);
        $this->postJson(self::URL.'/'.$order->id.'/capture')->assertStatus(409);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/capture'));
    }

    public static function mismatchedOrders(): array
    {
        return [
            'order' => ['id', 'OTHER-ORDER'], 'intent' => ['intent', 'AUTHORIZE'],
            'owner-reference' => ['purchase_units.0.custom_id', 'OTHER-LOCAL-ORDER'],
            'merchant' => ['purchase_units.0.payee.merchant_id', 'OTHER-MERCHANT'],
            'currency' => ['purchase_units.0.amount.currency_code', 'JPY'],
            'not-approved' => ['status', 'CREATED'],
        ];
    }

    #[DataProvider('captureStatuses')]
    public function test_only_matching_completed_capture_is_success(string $remoteStatus, string $localStatus, int $httpStatus): void
    {
        $body = $this->fixture()['paypal']['completed'];
        $body['purchase_units'][0]['payments']['captures'][0]['status'] = $remoteStatus;
        $this->paypal([self::API.'/v2/checkout/orders/DEMO-ORDER-001/capture' => Http::response($body, 201)]);
        $id = $this->postJson(self::URL, $this->fixture()['requests']['create'])->assertCreated()->json('id');
        $this->postJson(self::URL.'/'.$id.'/capture')->assertStatus($httpStatus)->assertJsonPath('status', $localStatus);
    }

    public static function captureStatuses(): array
    {
        return [['PENDING', 'pending', 202], ['DECLINED', 'failed', 200], ['UNKNOWN', 'capture_unknown', 202]];
    }

    public function test_create_timeout_reuses_the_original_remote_operation_key(): void
    {
        $this->paypal([self::API.'/v2/checkout/orders' => Http::sequence()->pushStatus(503)->push($this->fixture()['paypal']['created'], 201)]);
        $input = $this->fixture()['requests']['create'];
        $first = $this->postJson(self::URL, $input)->assertStatus(202)->assertJsonPath('status', 'create_unknown')->json('id');
        $this->postJson(self::URL, $input)->assertOk()->assertJsonPath('id', $first)->assertJsonPath('status', 'created');
        $requests = Http::recorded(fn ($request) => $request->url() === self::API.'/v2/checkout/orders')->values();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0][0]->header('PayPal-Request-Id'), $requests[1][0]->header('PayPal-Request-Id'));
        $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
        $this->assertDatabaseCount('demo_orders', 1);
    }

    public function test_uncertain_order_outside_retry_window_does_not_create_again(): void
    {
        $input = $this->fixture()['requests']['create'];
        DemoOrder::factory()->create(['client_request_id' => $input['client_request_id'], 'created_at' => now()->subHours(6)]);
        $this->postJson(self::URL, $input)->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_unowned_memo_and_missing_sandbox_config_cannot_start_checkout(): void
    {
        $input = $this->fixture()['requests']['create'];
        $this->postJson(self::URL, $input + ['memo_id' => fake()->uuid()])->assertUnprocessable();
        config()->set('demo_shop.paypal.secret', '');
        $this->postJson(self::URL, $input)->assertStatus(503);
        Http::assertNothingSent();
    }
}
