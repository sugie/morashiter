<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\IncidentRevision;
use Illuminate\Console\Command;

/**
 * Fills incidents.company_id for incidents approved before the column existed, following
 * revision -> review -> official document -> official domain -> company. Reports only unless --apply.
 * Never replaces an existing link. Withdrawn incidents are included (the public side skips them).
 */
class IncidentsLinkCompanies extends Command
{
    protected $signature = 'incidents:link-companies {--apply : write the links (default: report only)}';

    protected $description = 'Link incidents to the company of their official source.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $linked = 0;
        $untraceable = [];

        // Loaded up front: updating company_id while paging over "company_id is null" would skip rows.
        Incident::query()->whereNull('company_id')->orderBy('display_code')->get()->each(function (Incident $incident) use ($apply, &$linked, &$untraceable): void {
            $revision = IncidentRevision::query()
                ->where('incident_id', $incident->id)->where('status', 'approved')->whereNotNull('incident_review_id')
                ->with('review.officialDocument.officialDomain.company')
                ->orderByDesc('revision_number')->first();
            $company = $revision?->review?->officialDocument?->officialDomain?->company;
            if ($company === null) {
                $untraceable[] = $incident->display_code;

                return;
            }
            $this->line("{$incident->display_code} → {$company->name}");
            if ($apply) {
                $incident->update(['company_id' => $company->id]);
            }
            $linked++;
        });

        foreach ($untraceable as $code) {
            $this->warn("{$code}: 公式発表からたどれません。管理画面の「訂正版の作成」で会社を選んでください。");
        }
        $this->info($apply
            ? "{$linked} 件を会社に結び付けました。"
            : "{$linked} 件を結び付けられます（確認だけで、書き込んでいません。書き込むには --apply を付けて実行してください）。");

        return self::SUCCESS;
    }
}
