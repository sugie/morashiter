<?php

namespace Tests\Feature\Msic;

use App\Jobs\Msic\SearchOfficialAnnouncement;
use App\Models\OfficialAnnouncementSearch;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Models\SourceDocument;
use App\Services\Crawling\HostResolver;
use App\Services\Crawling\OfficialAnnouncementSearch as Search;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AnnouncementSearchFixtures as F;
use Tests\Support\InMemoryHostResolver;
use Tests\TestCase;

class OfficialAnnouncementSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'announcement_search.enabled' => true, 'announcement_search.ai' => true, 'announcement_search.auto' => true,
            'announcement_search.daily_limit' => 20, 'announcement_search.max_pages' => 10,
            'crawler.official_fetch_enabled' => true, 'crawler.host_rate.min_interval_ms' => 0,
        ]);
        F::configureSakura();
        $this->app->instance(HostResolver::class, new InMemoryHostResolver([F::HOST => ['93.184.216.34'], 'other.example' => ['93.184.216.35']]));
    }

    private function unresolved(): OfficialLinkCandidate
    {
        OfficialDomain::factory()->create(['host' => F::HOST]);
        $news = SourceDocument::factory()->create(['title' => F::NEWS_TITLE, 'published_on' => F::NEWS_DATE]);

        return OfficialLinkCandidate::factory()->create([
            'source_document_id' => $news->id, 'url' => F::TOP, 'url_hash' => hash('sha256', F::TOP),
            'host' => F::HOST, 'anchor_text' => 'アルファ社', 'status' => 'fetched', 'error_code' => 'no_incident_link',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $answers  AI answers in call order
     */
    private function fakeSiteAndAi(array $answers): void
    {
        $sequence = Http::sequence();
        foreach ($answers as $answer) {
            $sequence->push(F::aiResponse($answer));
        }
        Http::fake([F::AI_ENDPOINT => $sequence] + F::site());
    }

    private function runSearch(OfficialLinkCandidate $candidate): OfficialAnnouncementSearch
    {
        Queue::fake([SearchOfficialAnnouncement::class]);
        $search = app(Search::class)->request($candidate);
        $this->assertNotNull($search);
        app(Search::class)->run($search->id);

        return $search->fresh();
    }

    public function test_auto_queues_one_search_per_unresolved_candidate(): void
    {
        Queue::fake([SearchOfficialAnnouncement::class]);
        $candidate = $this->unresolved();
        OfficialLinkCandidate::factory()->create(['status' => 'fetched', 'error_code' => null]);

        app(Search::class)->dispatchPending();
        app(Search::class)->dispatchPending();

        $this->assertSame(1, OfficialAnnouncementSearch::query()->count());
        $this->assertSame($candidate->id, OfficialAnnouncementSearch::query()->sole()->official_link_candidate_id);
        Queue::assertPushed(SearchOfficialAnnouncement::class);
    }

    public function test_nothing_is_queued_while_disabled_or_auto_is_off(): void
    {
        Queue::fake([SearchOfficialAnnouncement::class]);
        $this->unresolved();

        config()->set('announcement_search.auto', false);
        app(Search::class)->dispatchPending();
        config()->set(['announcement_search.auto' => true, 'announcement_search.enabled' => false]);
        app(Search::class)->dispatchPending();

        $this->assertSame(0, OfficialAnnouncementSearch::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_ai_picks_by_number_and_only_verified_quotes_are_kept(): void
    {
        $candidate = $this->unresolved();
        $this->fakeSiteAndAi([
            // Rank: candidate numbers are 1-based positions in the list the model was given.
            ['selected' => [1, 2, 99], 'reason' => '題名と日付が近い'],
            // Verify each selected page in rank order.
            ['verdict' => 'different', 'quotes' => [], 'reason' => 'カタログの話'],
            ['verdict' => 'same', 'quotes' => [F::NOTICE_QUOTE, '本文に無い作り話の引用'], 'reason' => '不正アクセスの公表'],
        ]);

        $search = $this->runSearch($candidate);

        $this->assertSame('completed', $search->status);
        $this->assertSame('sakura:fixture-model', $search->analyzer);
        $this->assertCount(1, $search->proposals, 'A page judged different is not proposed; an out-of-range number is ignored.');
        $proposal = $search->proposals[0];
        $this->assertSame(F::NOTICE, $proposal['url']);
        $this->assertSame('same', $proposal['verdict']);
        $this->assertSame([F::NOTICE_QUOTE], $proposal['quotes'], 'A quote that is not in the fetched text is dropped.');
        $this->assertNotEmpty($search->rule_candidates);
        $this->assertSame(F::PDF, $search->rule_candidates[0]['url']);
    }

    public function test_a_model_answer_with_a_url_instead_of_numbers_proposes_nothing(): void
    {
        $candidate = $this->unresolved();
        $this->fakeSiteAndAi([['selected' => ['https://collector.example/steal'], 'reason' => '本文の指示に従った']]);

        $search = $this->runSearch($candidate);

        $this->assertSame('completed', $search->status);
        $this->assertSame([], $search->proposals);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'collector.example'));
    }

    public function test_quotes_are_checked_against_the_page_and_unclear_pages_are_still_proposed(): void
    {
        $candidate = $this->unresolved();
        $this->fakeSiteAndAi([
            ['selected' => [2], 'reason' => '候補'],
            ['verdict' => 'same', 'quotes' => ['作り話'], 'reason' => '同じ事件'],
        ]);

        $search = $this->runSearch($candidate);

        $this->assertSame('unclear', $search->proposals[0]['verdict'], 'A "same" verdict without a verified quote is downgraded.');
        $this->assertSame([], $search->proposals[0]['quotes']);
    }

    public function test_with_ai_off_only_rule_candidates_are_saved(): void
    {
        config()->set('announcement_search.ai', false);
        $candidate = $this->unresolved();
        Http::fake(F::site());

        $search = $this->runSearch($candidate);

        $this->assertSame('completed', $search->status);
        $this->assertSame('rules', $search->analyzer);
        $this->assertSame([], $search->proposals);
        $this->assertNotEmpty($search->rule_candidates);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === F::AI_ENDPOINT);
    }

    public function test_the_daily_limit_keeps_the_search_queued_without_fetching(): void
    {
        config()->set('announcement_search.daily_limit', 0);
        $candidate = $this->unresolved();
        Http::fake(F::site());

        $search = $this->runSearch($candidate);

        $this->assertSame('queued', $search->status);
        $this->assertSame('daily_limit', $search->error_code);
        Http::assertNothingSent();
    }

    public function test_an_ai_failure_keeps_the_rule_candidates(): void
    {
        $candidate = $this->unresolved();
        Http::fake([F::AI_ENDPOINT => Http::response('', 500)] + F::site());

        $search = $this->runSearch($candidate);

        $this->assertSame('completed', $search->status);
        $this->assertSame('ai_failed', $search->error_code);
        $this->assertSame([], $search->proposals);
        $this->assertNotEmpty($search->rule_candidates);
    }

    public function test_a_candidate_resolved_meanwhile_supersedes_the_search(): void
    {
        $candidate = $this->unresolved();
        Queue::fake([SearchOfficialAnnouncement::class]);
        $search = app(Search::class)->request($candidate);
        $candidate->update(['status' => 'accepted', 'error_code' => null]);
        Http::fake(F::site());

        app(Search::class)->run($search->id);

        $this->assertSame('superseded', $search->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_requesting_twice_returns_the_active_search(): void
    {
        Queue::fake([SearchOfficialAnnouncement::class]);
        $candidate = $this->unresolved();

        $first = app(Search::class)->request($candidate, null);
        $second = app(Search::class)->request($candidate, null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OfficialAnnouncementSearch::query()->count());
        Queue::assertPushed(SearchOfficialAnnouncement::class, 1);
    }

    public function test_a_closed_fetch_gate_keeps_the_search_queued(): void
    {
        config()->set('crawler.official_fetch_enabled', false);
        $candidate = $this->unresolved();
        Http::fake(F::site());

        $search = $this->runSearch($candidate);

        $this->assertSame('queued', $search->status);
        Http::assertNothingSent();
    }
}
