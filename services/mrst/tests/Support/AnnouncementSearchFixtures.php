<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * A fictional company site for official-announcement search: robots, sitemap, RSS,
 * a news listing, an announcement whose heading has no incident words, a PDF and noise.
 */
class AnnouncementSearchFixtures
{
    public const HOST = 'www.alpha-corp.example.jp';

    public const TOP = 'https://www.alpha-corp.example.jp/';

    public const NOTICE = 'https://www.alpha-corp.example.jp/news/2026/1019.html';

    public const PDF = 'https://www.alpha-corp.example.jp/news/2026/1015.pdf';

    public const SITEMAP_ONLY = 'https://www.alpha-corp.example.jp/news/2026/1020.html';

    public const DISALLOWED = 'https://www.alpha-corp.example.jp/secret/hidden.html';

    public const OLD = 'https://www.alpha-corp.example.jp/old/2020.html';

    public const AI_ENDPOINT = 'https://ai.example/v1/chat/completions';

    public const NEWS_TITLE = 'アルファ社で不正アクセス、個人情報が流出';

    public const NEWS_DATE = '2026-10-21';

    public const NOTICE_QUOTE = '第三者による不正アクセスを受け、お客様の個人情報が流出した可能性があることが判明しました。';

    public static function html(string $body, string $head = ''): string
    {
        return '<!doctype html><html><head><meta charset="utf-8">'.$head.'</head><body>'.$body.'</body></html>';
    }

    /**
     * @return array<string, mixed>
     */
    public static function site(): array
    {
        $html = ['Content-Type' => 'text/html; charset=utf-8'];

        return [
            self::TOP => Http::response(self::html(
                '<a href="/news/">お知らせ</a><a href="/products/">製品情報</a><a href="/privacy/">プライバシーポリシー</a><a href="https://other.example/notice.html">外部のお知らせ</a>',
                '<link rel="alternate" type="application/rss+xml" href="/feed.xml">'
            ), 200, $html),
            'https://'.self::HOST.'/robots.txt' => Http::response("User-agent: *\nDisallow: /secret/\nSitemap: https://".self::HOST."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']),
            'https://'.self::HOST.'/sitemap.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>'.self::SITEMAP_ONLY.'</loc><lastmod>2026-10-20</lastmod></url>'
                .'<url><loc>'.self::DISALLOWED.'</loc><lastmod>2026-10-20</lastmod></url>'
                .'<url><loc>'.self::OLD.'</loc><lastmod>2020-01-01</lastmod></url>'
                .'<url><loc>https://other.example/x.html</loc></url>'
                .'</urlset>', 200, ['Content-Type' => 'application/xml']),
            'https://'.self::HOST.'/feed.xml' => Http::response('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>アルファ社</title>'
                .'<item><title>当社サービスに関するお知らせ</title><link>'.self::NOTICE.'</link><pubDate>Mon, 19 Oct 2026 09:00:00 +0900</pubDate></item>'
                .'</channel></rss>', 200, ['Content-Type' => 'application/rss+xml']),
            'https://'.self::HOST.'/news/' => Http::response(self::html(
                '<ul><li><a href="/news/2026/1019.html">2026.10.19 当社サービスに関するお知らせ</a></li>'
                .'<li><a href="/news/2026/1015.pdf">2026年10月15日 個人情報漏えいに関するお詫び（PDF）</a></li>'
                .'<li><a href="/privacy/">個人情報の取扱い</a></li></ul>'
            ), 200, $html),
            self::NOTICE => Http::response(self::html('<main><h1>当社サービスに関するお知らせ</h1><p>'.self::NOTICE_QUOTE.'</p><p>対象のお客様には個別にご連絡しています。</p></main>'), 200, $html),
            self::PDF => Http::response(PdfFixture::withText(['Product catalog revision. No changes to the content.']), 200, ['Content-Type' => 'application/pdf']),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function aiResponse(array $content): array
    {
        return ['choices' => [['message' => ['content' => json_encode($content, JSON_UNESCAPED_UNICODE)]]]];
    }

    public static function configureSakura(): void
    {
        config()->set([
            'ai.provider' => 'sakura', 'ai.sakura.base_url' => 'https://ai.example/v1',
            'ai.sakura.api_key' => 'fixture-token', 'ai.sakura.model' => 'fixture-model',
        ]);
    }
}
