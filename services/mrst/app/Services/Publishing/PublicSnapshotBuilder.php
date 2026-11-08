<?php

namespace App\Services\Publishing;

use App\Models\Incident;
use App\Models\MerchantDomain;

/**
 * The public data msgn hands to the static build: only the site's Incident fields of each
 * incident's latest approved revision, plus withdrawal notices and the shopping hosts the extension
 * may match ("merchants"; the static build turns them into bucketed JSON). Deterministic: no timestamps
 * of the build itself, fixed key and row order, so the same data always has the same hash.
 */
class PublicSnapshotBuilder
{
    public const SCHEMA_VERSION = 'public-snapshot.v1';

    private const NOTICE_TIMEZONE = 'Asia/Tokyo';

    /**
     * @return array{schema_version: string, data_as_of: ?string, incidents: list<array<string, ?string>>, withdrawals: list<array<string, string>>, merchants: array{prefix_length: int, domains: list<array{registrable_domain: string, host: string, company: string, incident_ids: list<string>}>}}
     */
    public function build(): array
    {
        $incidents = Incident::query()->with('currentRevision')->orderBy('display_code')->get()
            ->filter(fn (Incident $incident): bool => $incident->currentRevision !== null);

        $incidents->each(fn (Incident $incident) => $incident->currentRevision->setRelation('incident', $incident));

        $published = $incidents->whereNull('withdrawn_at')
            ->map(fn (Incident $incident): array => $incident->currentRevision->toPublicArray())
            ->values()
            ->all();

        $withdrawals = $incidents->whereNotNull('withdrawn_at')
            ->map(fn (Incident $incident): array => [
                'id' => $incident->display_code,
                'name' => $incident->currentRevision->name,
                'company' => $incident->currentRevision->company,
                'date' => $incident->withdrawn_at->setTimezone(self::NOTICE_TIMEZONE)->format('Y/m/d'),
                'reason' => (string) $incident->withdrawal_reason,
            ])
            ->values()
            ->all();

        $dates = array_map(fn (array $row): string => str_replace('/', '-', (string) $row['date']), $published);
        sort($dates);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'data_as_of' => $dates === [] ? null : end($dates),
            'incidents' => $published,
            'withdrawals' => $withdrawals,
            'merchants' => $this->merchants($incidents->whereNull('withdrawn_at')->values()->all()),
        ];
    }

    /**
     * Enabled shopping hosts whose company has at least one published incident, in a fixed order.
     * Only public facts: host, its registrable domain, the company name and the incident codes.
     *
     * @param  list<Incident>  $published
     * @return array{prefix_length: int, domains: list<array{registrable_domain: string, host: string, company: string, incident_ids: list<string>}>}
     */
    private function merchants(array $published): array
    {
        $codesByCompany = [];
        foreach ($published as $incident) {
            if ($incident->company_id !== null) {
                $codesByCompany[$incident->company_id][] = $incident->display_code;
            }
        }

        $rows = MerchantDomain::query()->toBase()
            ->join('companies', 'companies.id', '=', 'merchant_domains.company_id')
            ->whereNull('merchant_domains.disabled_at')
            ->orderBy('merchant_domains.registrable_domain')->orderBy('merchant_domains.host')
            ->get(['merchant_domains.host', 'merchant_domains.registrable_domain', 'merchant_domains.company_id', 'companies.name as company_name']);

        $domains = [];
        foreach ($rows as $row) {
            $codes = $codesByCompany[$row->company_id] ?? [];
            if ($codes === []) {
                continue;
            }
            sort($codes);
            $domains[] = [
                'registrable_domain' => (string) $row->registrable_domain,
                'host' => (string) $row->host,
                'company' => (string) $row->company_name,
                'incident_ids' => array_values(array_unique($codes)),
            ];
        }

        $length = (int) config('msgn.merchant_prefix_length', 2);

        return ['prefix_length' => $length >= 2 && $length <= 4 ? $length : 2, 'domains' => $domains];
    }

    public function json(): string
    {
        return json_encode($this->build(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    }

    public function sha256(): string
    {
        return hash('sha256', $this->json());
    }
}
