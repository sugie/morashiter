<?php

namespace Database\Factories;

use App\Models\OfficialLinkCandidate;
use App\Models\SourceDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialLinkCandidate>
 */
class OfficialLinkCandidateFactory extends Factory
{
    public function definition(): array
    {
        $url = 'https://www.test-corp.example.co.jp/news/'.fake()->unique()->numberBetween(1000, 9999).'.html';

        return [
            'source_document_id' => SourceDocument::factory(),
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'host' => 'www.test-corp.example.co.jp',
            'anchor_text' => '同社の発表',
            'status' => 'pending',
        ];
    }
}
