<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OfficialDomainInvestigationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'host' => 'alpha.example', 'generation' => 1, 'status' => 'queued',
            'recommendation' => 'hold', 'rule_version' => 'overview-human-review-v1',
            'prompt_version' => 'organization-evidence-v1',
        ];
    }
}
