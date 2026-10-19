<?php

namespace Tests\Unit\Crawling;

use App\Services\Crawling\PythonArticleExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PythonArticleExtractorResultTest extends TestCase
{
    private const URL = 'https://news.example.test/articles/1';

    /**
     * @return array<string, mixed>
     */
    private function validResult(): array
    {
        return [
            'adapter_code' => 'generic_article',
            'extraction_version' => 'generic_article_v2',
            'url' => self::URL,
            'title' => '見出し',
            'published_on' => '2026-10-16',
            'body_chars' => 480,
            'official_links' => [['url' => 'https://www.test-corp.example.co.jp/news/1.html', 'text' => '同社の発表']],
        ];
    }

    public function test_valid_result_is_returned(): void
    {
        $result = (new PythonArticleExtractor)->validatedResult($this->validResult(), 'generic_article', self::URL);

        $this->assertSame($this->validResult(), $result);
    }

    public function test_article_text_is_dropped_even_if_the_extractor_returns_it(): void
    {
        $raw = $this->validResult() + ['body_text' => 'ニュース記事の本文', 'html' => '<p>本文</p>'];

        $result = (new PythonArticleExtractor)->validatedResult($raw, 'generic_article', self::URL);

        $this->assertArrayNotHasKey('body_text', $result);
        $this->assertArrayNotHasKey('html', $result);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidResults(): array
    {
        return [
            'missing official_links' => [['official_links' => null]],
            'official_links not a list' => [['official_links' => ['url' => 'https://a.example/']]],
            'link without text' => [['official_links' => [['url' => 'https://a.example/']]]],
            'link url not a string' => [['official_links' => [['url' => 1, 'text' => 'x']]]],
            'body_chars zero' => [['body_chars' => 0]],
            'body_chars not an int' => [['body_chars' => '480']],
            'empty title' => [['title' => ' ']],
            'other adapter' => [['adapter_code' => 'fixture_news']],
            'other url' => [['url' => 'https://evil.example/']],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidResults')]
    public function test_invalid_result_is_rejected(array $override): void
    {
        $raw = array_merge($this->validResult(), $override);

        $this->expectException(RuntimeException::class);
        (new PythonArticleExtractor)->validatedResult($raw, 'generic_article', self::URL);
    }
}
