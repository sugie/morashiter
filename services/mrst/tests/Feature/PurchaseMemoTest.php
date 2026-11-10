<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchaseMemoTest extends TestCase
{
    private const URL = 'http://aozora-zakka.localhost/demo-shop/memo';

    private const AI = 'https://ai.example.invalid/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set(['demo_shop.enabled' => true, 'demo_shop.ai.enabled' => true,
            'demo_shop.ai.base_url' => 'https://ai.example.invalid/v1',
            'demo_shop.ai.key' => 'demo-test-secret', 'demo_shop.ai.model' => 'test-model']);
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/demo-shop/scenario.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_generates_cited_memo_and_reuses_valid_cache(): void
    {
        Http::fake([self::AI => Http::response(['choices' => [['message' => ['content' => json_encode($this->fixture()['memo_valid'])]]]])]);
        $payload = ['product_id' => 'TOTE-001', 'language' => 'en'];
        $first = $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('generated_by', 'ai')
            ->assertJsonPath('sources.0.path', '/demo-shop/notices/DEMO-NOTICE-001')->json();
        $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('memo_id', $first['memo_id']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === self::AI && str_contains($r['messages'][1]['content'], 'Fictional demo notice')
            && ! str_contains($r['messages'][1]['content'], 'demo-test-secret'));
    }

    #[DataProvider('invalidOutputs')]
    public function test_rejects_untrusted_ai_output(string $case): void
    {
        $memo = $this->fixture()['memo_valid'];
        if ($case === 'source') {
            $memo['facts'][0]['source_ids'] = ['UNKNOWN'];
        } elseif ($case === 'html') {
            $memo['facts'][0]['text'] = '<script>alert(1)</script>';
        } elseif ($case === 'guarantee') {
            $memo['facts'][0]['text'] = 'This store is safe. Buy now.';
        } elseif ($case === 'empty') {
            $memo['facts'] = [];
        } elseif ($case === 'long') {
            $memo['general_tips'] = [str_repeat('x', 501)];
        }
        $text = $case === 'json' ? '{bad json' : json_encode($memo);
        Http::fake([self::AI => Http::response(['choices' => [['message' => ['content' => $text]]]])]);
        $this->postJson(self::URL, ['product_id' => 'TOTE-001', 'language' => 'en'])
            ->assertStatus(503)->assertJsonPath('error', 'memo_unavailable');
    }

    public static function invalidOutputs(): array
    {
        return array_combine(['source', 'html', 'guarantee', 'empty', 'long', 'json'], array_map(fn ($s) => [$s], ['source', 'html', 'guarantee', 'empty', 'long', 'json']));
    }

    public function test_disabled_ai_and_unknown_request_fields_do_not_send_data(): void
    {
        $this->postJson(self::URL, ['product_id' => 'TOTE-001', 'language' => 'en', 'url' => 'https://private.invalid'])->assertUnprocessable();
        config()->set('demo_shop.ai.enabled', false);
        $this->postJson(self::URL, ['product_id' => 'TOTE-001', 'language' => 'en'])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_outage_and_daily_budget_never_fabricate_a_memo(): void
    {
        Http::fake([self::AI => Http::response([], 500)]);
        config()->set('demo_shop.ai.daily_limit', 1);
        $payload = ['product_id' => 'TOTE-001', 'language' => 'en'];
        $this->postJson(self::URL, $payload)->assertStatus(503);
        $this->postJson(self::URL, $payload)->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_fourth_request_is_rate_limited_without_calling_ai(): void
    {
        Http::fake([self::AI => Http::response([], 503)]);
        $payload = ['product_id' => 'TOTE-001', 'language' => 'en'];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson(self::URL, $payload)->assertStatus(503);
        }
        $this->postJson(self::URL, $payload)->assertStatus(429);
        Http::assertSentCount(3);
    }

    public function test_memo_cache_expires_after_one_day(): void
    {
        Http::fake([self::AI => Http::response(['choices' => [['message' => ['content' => json_encode($this->fixture()['memo_valid'])]]]])]);
        $payload = ['product_id' => 'TOTE-001', 'language' => 'en'];
        $first = $this->postJson(self::URL, $payload)->assertOk()->json('memo_id');
        $this->travel(23)->hours();
        $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('memo_id', $first);
        Http::assertSentCount(1);
        $this->travel(2)->hours();
        $second = $this->postJson(self::URL, $payload)->assertOk()->json('memo_id');
        $this->assertNotSame($first, $second);
        Http::assertSentCount(2);
    }
}
