<?php

namespace App\Console\Commands;

use App\Exceptions\AiAnalysisFailure;
use App\Models\OfficialDocument;
use App\Services\Ai\AiUsageBudget;
use App\Services\Ai\SakuraIncidentAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only preflight for the AI settings, daily caps and review backlog.
 * Never prints the API key. Only --ping talks to the provider, and that call counts toward the daily cap.
 */
class AiCheck extends Command
{
    protected $signature = 'ai:check
                            {--ping : Send one small request to Sakura AI (counts toward AI_MAX_ANALYSES_PER_DAY)}';

    protected $description = 'Show the AI settings, today\'s usage and the review backlog; optionally ping Sakura AI.';

    public function handle(AiUsageBudget $budget): int
    {
        $provider = (string) config('ai.provider', 'mock');
        $problems = $this->problems($provider);

        $this->line("provider={$provider} model=".($this->text('ai.sakura.model') ?? '-').' base_url='.($this->text('ai.sakura.base_url') ?? '-'));
        $this->line('api_key='.($this->text('ai.sakura.api_key') === null ? 'missing' : 'set'));
        $this->line('max_retries='.config('ai.max_retries').' max_attempts_per_document='.config('ai.max_attempts_per_document')
            .' max_input_characters='.config('ai.max_input_characters'));

        $analysisCap = (int) config('ai.max_analyses_per_day', 20);
        $this->line("analyses today (sakura): {$budget->usedToday('sakura')} of {$analysisCap}");

        $researchEnabled = (bool) config('domain_research.enabled');
        $researchDay = now(config('domain_research.budget_timezone'))->toDateString();
        $researchUsed = (int) DB::table('domain_research_budgets')->where('day', $researchDay)->value('used');
        $this->line('domain research: '.($researchEnabled ? 'enabled' : 'disabled')
            ." today {$researchUsed} of ".(int) config('domain_research.daily_limit', 10));

        $this->line('documents without a review: '.OfficialDocument::query()->whereDoesntHave('review')->count());
        DB::table('incident_reviews')
            ->select('status', 'analyzer', 'error_code', DB::raw('count(*) as total'))
            ->groupBy('status', 'analyzer', 'error_code')
            ->orderBy('status')->orderBy('analyzer')->orderBy('error_code')
            ->get()
            ->each(fn (object $row) => $this->line("reviews {$row->status}/{$row->analyzer}".($row->error_code ? "/{$row->error_code}" : '').": {$row->total}"));

        if ($researchEnabled && $provider !== 'sakura') {
            $this->warn('DOMAIN_RESEARCH_ENABLED=true but AI_PROVIDER is not sakura: research results will all be held (provider_disabled).');
        }
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems !== []) {
            return self::FAILURE;
        }

        return $this->option('ping') ? $this->ping($provider, $budget) : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function problems(string $provider): array
    {
        if ($provider === 'mock') {
            return [];
        }
        if ($provider !== 'sakura') {
            return ['AI_PROVIDER must be "mock" or "sakura".'];
        }

        $problems = [];
        $baseUrl = $this->text('ai.sakura.base_url');
        if ($baseUrl === null || ! str_starts_with($baseUrl, 'https://')) {
            $problems[] = 'SAKURA_AI_BASE_URL must be an https:// URL.';
        }
        if ($this->text('ai.sakura.model') === null) {
            $problems[] = 'SAKURA_AI_MODEL is required when AI_PROVIDER=sakura.';
        }
        if ($this->text('ai.sakura.api_key') === null) {
            $problems[] = 'SAKURA_AI_API_KEY is required when AI_PROVIDER=sakura.';
        }

        return $problems;
    }

    private function ping(string $provider, AiUsageBudget $budget): int
    {
        if ($provider !== 'sakura') {
            $this->error('--ping needs AI_PROVIDER=sakura.');

            return self::FAILURE;
        }
        if (! $budget->reserve('sakura')) {
            $this->error('Daily cap reached (AI_MAX_ANALYSES_PER_DAY); not sending.');

            return self::FAILURE;
        }

        try {
            $analyzer = SakuraIncidentAnalyzer::fromConfig((array) config('ai', []));
        } catch (AiAnalysisFailure $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $started = hrtime(true);
        $status = $analyzer->ping();
        $elapsedMs = intdiv(hrtime(true) - $started, 1_000_000);

        if ($status === null) {
            $this->error("ping: could not reach Sakura AI ({$elapsedMs} ms).");

            return self::FAILURE;
        }
        if ($status < 200 || $status >= 300) {
            $this->error("ping: HTTP {$status} ({$elapsedMs} ms).");
            if ($status === 401) {
                $this->error('Check SAKURA_AI_API_KEY (UUID:secret).');
            }

            return self::FAILURE;
        }
        $this->info("ping: HTTP {$status} ({$elapsedMs} ms). Counted as 1 call today.");

        return self::SUCCESS;
    }

    private function text(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
