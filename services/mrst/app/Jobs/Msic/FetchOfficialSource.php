<?php

namespace App\Jobs\Msic;

use App\Exceptions\HttpFetchFailure;
use App\Exceptions\UrlPolicyViolation;
use App\Models\CrawlSource;
use App\Models\OfficialDocument;
use App\Models\OfficialDomain;
use App\Models\OfficialLinkCandidate;
use App\Services\Crawling\FetchResult;
use App\Services\Crawling\HostRateLimiter;
use App\Services\Crawling\OfficialFetchGate;
use App\Services\Crawling\OfficialPageLinkFinder;
use App\Services\Crawling\SafeHttpFetcher;
use DOMDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Fetches a company's own announcement for an accepted link candidate. Only hosts an admin
 * registered as official are contacted; a top page is searched for incident announcements.
 */
class FetchOfficialSource implements ShouldQueue
{
    use Queueable;

    public int $timeout = 110;

    public int $tries = 1;

    private const SUPPORTED_TYPES = ['text/html' => 'html', 'application/xhtml+xml' => 'html', 'application/pdf' => 'pdf'];

    public function __construct(public int $candidateId)
    {
        $this->onQueue('msnc');
    }

    public function handle(
        SafeHttpFetcher $fetcher,
        HostRateLimiter $rateLimiter,
        OfficialPageLinkFinder $finder,
        OfficialFetchGate $gate,
    ): void {
        if ($gate->closedReason() !== null) {
            return;
        }

        $candidate = DB::transaction(function (): ?OfficialLinkCandidate {
            $candidate = OfficialLinkCandidate::query()->whereKey($this->candidateId)->lockForUpdate()->first();
            if ($candidate === null || $candidate->status !== 'accepted') {
                return null;
            }
            $candidate->update(['status' => 'running', 'error_code' => null]);

            return $candidate;
        });
        if ($candidate === null) {
            return;
        }

        $domain = OfficialDomain::query()->where('host', $candidate->host)->first();
        if ($domain === null) {
            $this->finish($candidate, 'failed', 'not_registered');

            return;
        }
        $source = new CrawlSource(['name' => "official:{$domain->host}", 'allowed_hosts' => [$domain->host]]);

        try {
            $rateLimiter->throttle($domain->host);
            $result = $fetcher->fetch($candidate->url, $source);
            if ($result->status !== 200) {
                $this->finish($candidate, 'failed', 'http_non_200');

                return;
            }

            if ($this->extension($result) === 'html' && $finder->isTopPage($candidate->url)) {
                $this->collectFromTopPage($candidate, $domain, $source, $result, $fetcher, $rateLimiter, $finder);

                return;
            }

            if ($this->extension($result) === null) {
                $this->finish($candidate, 'failed', 'unsupported_content_type');

                return;
            }
            $this->store($candidate, $domain, $candidate->url, $result);
            $this->finish($candidate, 'fetched');
        } catch (UrlPolicyViolation) {
            $this->finish($candidate, 'failed', 'url_policy_violation');
        } catch (HttpFetchFailure $e) {
            $this->finish($candidate, 'failed', $e->failureCode);
        } catch (Throwable $e) {
            $this->finish($candidate, 'failed', 'fetch_failed');
            throw $e;
        }
    }

    private function collectFromTopPage(
        OfficialLinkCandidate $candidate,
        OfficialDomain $domain,
        CrawlSource $source,
        FetchResult $topPage,
        SafeHttpFetcher $fetcher,
        HostRateLimiter $rateLimiter,
        OfficialPageLinkFinder $finder,
    ): void {
        $links = $finder->find($topPage->body, $topPage->finalUrl);
        if ($links === []) {
            $this->finish($candidate, 'fetched', 'no_incident_link');

            return;
        }

        $stored = 0;
        foreach ($links as $link) {
            try {
                $rateLimiter->throttle($domain->host);
                $result = $fetcher->fetch($link['url'], $source);
            } catch (UrlPolicyViolation|HttpFetchFailure) {
                continue;
            }
            if ($result->status === 200 && $this->extension($result) !== null) {
                $this->store($candidate, $domain, $link['url'], $result);
                $stored++;
            }
        }

        $stored > 0
            ? $this->finish($candidate, 'fetched')
            : $this->finish($candidate, 'failed', 'incident_link_fetch_failed');
    }

    private function store(OfficialLinkCandidate $candidate, OfficialDomain $domain, string $url, FetchResult $result): void
    {
        $urlHash = hash('sha256', $url);
        $contentHash = hash('sha256', $result->body);
        if (OfficialDocument::query()->where('url_hash', $urlHash)->where('content_sha256', $contentHash)->exists()) {
            return;
        }

        $extension = $this->extension($result);
        $key = sprintf('msic/%d/%s/%s.%s', $domain->id, $urlHash, $contentHash, $extension);
        if (! Storage::disk('local')->put($key, $result->body)) {
            throw new RuntimeException('Official document could not be stored.');
        }

        $document = OfficialDocument::query()->create([
            'official_domain_id' => $domain->id,
            'link_candidate_id' => $candidate->id,
            'url' => $url,
            'url_hash' => $urlHash,
            'final_url' => $result->finalUrl,
            'content_sha256' => $contentHash,
            'content_type' => $this->contentType($result),
            'private_object_key' => $key,
            'title' => $extension === 'html' ? $this->title($result->body) : null,
            'http_status' => $result->status,
            'fetched_at' => now(),
        ]);

        AnalyzeOfficialDocument::dispatch($document->id)->afterCommit();
    }

    private function finish(OfficialLinkCandidate $candidate, string $status, ?string $errorCode = null): void
    {
        $candidate->update(['status' => $status, 'error_code' => $errorCode, 'fetched_at' => now()]);
    }

    private function contentType(FetchResult $result): string
    {
        return strtolower(trim(explode(';', (string) $result->header('Content-Type'))[0]));
    }

    private function extension(FetchResult $result): ?string
    {
        return self::SUPPORTED_TYPES[$this->contentType($result)] ?? null;
    }

    private function title(string $html): ?string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $title = $document->getElementsByTagName('title')->item(0)?->textContent;
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $title));

        return $title === '' ? null : mb_substr($title, 0, 500);
    }
}
