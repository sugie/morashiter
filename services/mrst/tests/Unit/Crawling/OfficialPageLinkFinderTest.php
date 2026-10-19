<?php

namespace Tests\Unit\Crawling;

use App\Services\Crawling\OfficialPageLinkFinder;
use PHPUnit\Framework\TestCase;

class OfficialPageLinkFinderTest extends TestCase
{
    private const PAGE = 'https://www.test-corp.example.co.jp/';

    private function html(string $body): string
    {
        return "<!doctype html><html><head><meta charset=\"utf-8\"></head><body>{$body}</body></html>";
    }

    public function test_finds_same_host_links_whose_text_mentions_an_incident(): void
    {
        $html = $this->html(<<<'HTML'
            <ul>
              <li><a href="/news/2026/1019.html">不正アクセスによる個人情報流出に関するお詫びとお知らせ</a></li>
              <li><a href="https://www.test-corp.example.co.jp/news/2026/1001.html">新商品発売のお知らせ</a></li>
              <li><a href="/info/notice.pdf">【重要】お客様情報漏えいの可能性について（PDF）</a></li>
            </ul>
        HTML);

        $links = (new OfficialPageLinkFinder)->find($html, self::PAGE);

        $this->assertSame([
            ['url' => 'https://www.test-corp.example.co.jp/news/2026/1019.html', 'text' => '不正アクセスによる個人情報流出に関するお詫びとお知らせ'],
            ['url' => 'https://www.test-corp.example.co.jp/info/notice.pdf', 'text' => '【重要】お客様情報漏えいの可能性について（PDF）'],
        ], $links);
    }

    public function test_ignores_other_hosts_fragments_duplicates_and_non_http_links(): void
    {
        $html = $this->html(<<<'HTML'
            <a href="https://other.example.com/leak.html">情報漏えいの解説記事</a>
            <a href="#incident">サイバー攻撃について</a>
            <a href="javascript:void(0)">不正アクセス</a>
            <a href="mailto:pr@test-corp.example.co.jp">流出のお問い合わせ</a>
            <a href="/news/a.html#top">ランサムウェア被害について</a>
            <a href="/news/a.html">ランサムウェア被害について（再掲）</a>
        HTML);

        $links = (new OfficialPageLinkFinder)->find($html, self::PAGE);

        $this->assertSame([
            ['url' => 'https://www.test-corp.example.co.jp/news/a.html', 'text' => 'ランサムウェア被害について'],
        ], $links);
    }

    public function test_returns_at_most_three_links(): void
    {
        $items = '';
        foreach (range(1, 5) as $i) {
            $items .= "<a href=\"/news/{$i}.html\">第{$i}報 不正アクセスについて</a>";
        }

        $links = (new OfficialPageLinkFinder)->find($this->html($items), self::PAGE);

        $this->assertCount(3, $links);
        $this->assertSame('https://www.test-corp.example.co.jp/news/1.html', $links[0]['url']);
    }

    public function test_policy_pages_are_not_incident_announcements(): void
    {
        $html = $this->html(<<<'HTML'
            <a href="/privacy/">個人情報保護方針</a>
            <a href="/security/">情報セキュリティ基本方針</a>
            <a href="/policy.html">特定個人情報等取扱基本方針</a>
            <a href="/privacy-policy/">プライバシーポリシー（個人情報の取り扱い）</a>
            <a href="/sub/kojinjyohou.html">個人情報の取り扱い</a>
            <a href="/news/1.html">第三者による不正アクセスに伴う個人情報漏えいに関するお詫び</a>
        HTML);

        $links = (new OfficialPageLinkFinder)->find($html, self::PAGE);

        $this->assertSame(['https://www.test-corp.example.co.jp/news/1.html'], array_column($links, 'url'));
    }

    public function test_personal_information_alone_is_not_an_incident(): void
    {
        $html = $this->html('<a href="/news/2.html">個人情報に関するお問い合わせ窓口</a><a href="/news/3.html">情報セキュリティへの取り組み</a>');

        $this->assertSame([], (new OfficialPageLinkFinder)->find($html, self::PAGE));
    }

    public function test_tracking_parameters_are_removed_so_duplicates_collapse(): void
    {
        $html = $this->html(<<<'HTML'
            <a href="/news/pdf/leak.pdf?openExternalBrowser=1&_ebx=abc.123">不正アクセスに関するお詫び（PDF）</a>
            <a href="/news/pdf/leak.pdf">不正アクセスに関するお詫び</a>
            <a href="/news/detail?id=42&utm_source=top">情報流出のお知らせ</a>
        HTML);

        $links = (new OfficialPageLinkFinder)->find($html, self::PAGE);

        $this->assertSame([
            'https://www.test-corp.example.co.jp/news/pdf/leak.pdf',
            'https://www.test-corp.example.co.jp/news/detail?id=42',
        ], array_column($links, 'url'));
    }

    public function test_page_without_incident_links_returns_nothing(): void
    {
        $html = $this->html('<a href="/company/">会社概要</a><a href="/news/">ニュース一覧</a>');

        $this->assertSame([], (new OfficialPageLinkFinder)->find($html, self::PAGE));
    }

    public function test_top_page_detection(): void
    {
        $finder = new OfficialPageLinkFinder;

        $this->assertTrue($finder->isTopPage('https://www.test-corp.example.co.jp/'));
        $this->assertTrue($finder->isTopPage('https://www.test-corp.example.co.jp'));
        $this->assertTrue($finder->isTopPage('https://www.test-corp.example.co.jp/index.html'));
        $this->assertTrue($finder->isTopPage('https://www.holdings.example.com/ja/'));
        $this->assertFalse($finder->isTopPage('https://www.test-corp.example.co.jp/news/2026/1019.html'));
        $this->assertFalse($finder->isTopPage('https://www.test-corp.example.co.jp/ir/notice.pdf'));
    }
}
