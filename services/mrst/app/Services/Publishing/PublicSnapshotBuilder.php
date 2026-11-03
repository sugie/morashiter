<?php

namespace App\Services\Publishing;

use App\Models\Incident;

/**
 * The public data msgn hands to the static build: only the site's Incident fields of each
 * incident's latest approved revision, plus withdrawal notices. Deterministic: no timestamps
 * of the build itself, fixed key and row order, so the same data always has the same hash.
 */
class PublicSnapshotBuilder
{
    public const SCHEMA_VERSION = 'public-snapshot.v1';

    private const NOTICE_TIMEZONE = 'Asia/Tokyo';

    /**
     * @return array{schema_version: string, data_as_of: ?string, incidents: list<array<string, ?string>>, withdrawals: list<array<string, string>>}
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
        ];
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
