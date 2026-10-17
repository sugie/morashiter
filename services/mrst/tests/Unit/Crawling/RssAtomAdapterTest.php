<?php

namespace Tests\Unit\Crawling;

use App\Exceptions\FeedParseFailure;
use App\Services\Crawling\RssAtomAdapter;
use Tests\TestCase;

class RssAtomAdapterTest extends TestCase
{
    private function adapter(): RssAtomAdapter
    {
        return new RssAtomAdapter();
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../fixtures/feeds/'.$name);
    }

    public function test_atom_feed_yields_three_entries_with_url_title_published(): void
    {
        $articles = $this->adapter()->parse($this->fixture('atom.xml'));

        $this->assertCount(3, $articles);
        $this->assertSame('http://fixture-news/incidents/incident-001.html', $articles[0]->url);
        $this->assertSame('テスト企業A、情報漏えいについて発表', $articles[0]->title);
        $this->assertSame('2026-10-01T09:00:00Z', $articles[0]->publishedAt);
        $this->assertSame('http://fixture-news/incidents/incident-003.html', $articles[2]->url);
    }

    public function test_atom_entry_without_link_or_title_is_skipped(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <id>a</id>
    <title>has title but no link</title>
  </entry>
  <entry>
    <id>b</id>
    <link rel="alternate" href="http://fixture-news/b"/>
  </entry>
  <entry>
    <id>c</id>
    <title>complete</title>
    <link rel="alternate" href="http://fixture-news/c"/>
    <published>2026-10-04T00:00:00Z</published>
  </entry>
</feed>
XML;

        $articles = $this->adapter()->parse($xml);

        $this->assertCount(1, $articles);
        $this->assertSame('http://fixture-news/c', $articles[0]->url);
    }

    public function test_rss_feed_yields_two_items(): void
    {
        $articles = $this->adapter()->parse($this->fixture('rss.xml'));

        $this->assertCount(2, $articles);
        $this->assertSame('http://fixture-news/rss/x.html', $articles[0]->url);
        $this->assertSame('テスト企業X、情報漏えい', $articles[0]->title);
        $this->assertSame('Wed, 01 Oct 2026 09:00:00 GMT', $articles[0]->publishedAt);
        $this->assertSame('http://fixture-news/rss/y.html', $articles[1]->url);
        $this->assertSame('y-2026-10-02', $articles[1]->rawId);
    }

    public function test_doctype_declaration_is_rejected(): void
    {
        try {
            $this->adapter()->parse($this->fixture('doctype.xml'));
            $this->fail('DOCTYPE should be rejected.');
        } catch (FeedParseFailure $e) {
            $this->assertSame('doctype_forbidden', $e->failureCode);
        }
    }

    public function test_xxe_payload_is_rejected_at_doctype(): void
    {
        try {
            $this->adapter()->parse($this->fixture('xxe.xml'));
            $this->fail('XXE payload should be rejected.');
        } catch (FeedParseFailure $e) {
            $this->assertSame('doctype_forbidden', $e->failureCode);
        }
    }

    public function test_malformed_xml_is_rejected(): void
    {
        try {
            $this->adapter()->parse($this->fixture('malformed.xml'));
            $this->fail('Malformed XML should be rejected.');
        } catch (FeedParseFailure $e) {
            $this->assertSame('malformed_xml', $e->failureCode);
        }
    }

    public function test_unknown_root_element_is_rejected(): void
    {
        try {
            $this->adapter()->parse('<?xml version="1.0"?><notafeed/>');
            $this->fail('Unknown root should be rejected.');
        } catch (FeedParseFailure $e) {
            $this->assertSame('unsupported_feed', $e->failureCode);
        }
    }

    public function test_entry_count_is_capped_at_one_hundred(): void
    {
        $entries = '';
        for ($i = 1; $i <= 150; $i++) {
            $entries .= sprintf(
                '<entry><id>u%d</id><title>Entry %d</title><link rel="alternate" href="http://fixture-news/%d"/></entry>',
                $i,
                $i,
                $i,
            );
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom">'.$entries.'</feed>';

        $articles = $this->adapter()->parse($xml);

        $this->assertCount(100, $articles);
        $this->assertSame('http://fixture-news/1', $articles[0]->url);
        $this->assertSame('http://fixture-news/100', $articles[99]->url);
    }
}
