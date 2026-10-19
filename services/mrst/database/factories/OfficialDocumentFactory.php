<?php

namespace Database\Factories;

use App\Models\OfficialDocument;
use App\Models\OfficialDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialDocument>
 */
class OfficialDocumentFactory extends Factory
{
    public function definition(): array
    {
        $url = 'https://www.test-corp.example.co.jp/news/'.fake()->unique()->numberBetween(1000, 99999).'.html';
        $html = '<html><head><title>不正アクセスに関するお詫び</title></head><body><p>当社は不正アクセスを受けました。</p></body></html>';

        return [
            'official_domain_id' => OfficialDomain::factory(),
            'link_candidate_id' => null,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'final_url' => $url,
            'content_sha256' => hash('sha256', $html.$url),
            'content_type' => 'text/html',
            'private_object_key' => 'msic/test/'.hash('sha256', $url).'.html',
            'title' => '不正アクセスに関するお詫び',
            'http_status' => 200,
            'fetched_at' => now(),
        ];
    }
}
