<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;

/**
 * Daily cap on real AI calls, counted per Japanese calendar day (configurable).
 * A call is reserved atomically before it is made, so retries count too.
 */
class AiUsageBudget
{
    public function reserve(string $provider): bool
    {
        $date = $this->today();
        $cap = (int) config('ai.max_analyses_per_day', 20);
        if ($cap < 1) {
            return false;
        }

        DB::table('ai_usage_days')->insertOrIgnore([
            'usage_date' => $date, 'provider' => $provider, 'calls' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('ai_usage_days')
            ->where('usage_date', $date)
            ->where('provider', $provider)
            ->where('calls', '<', $cap)
            ->update(['calls' => DB::raw('calls + 1'), 'updated_at' => now()]) === 1;
    }

    public function usedToday(string $provider): int
    {
        return (int) DB::table('ai_usage_days')->where('usage_date', $this->today())->where('provider', $provider)->value('calls');
    }

    private function today(): string
    {
        return now()->setTimezone((string) config('ai.budget_timezone', 'Asia/Tokyo'))->toDateString();
    }
}
