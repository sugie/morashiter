<?php

namespace Database\Seeders;

use App\Models\Incident;
use App\Models\IncidentRevision;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The five incidents a human checked for the first static release (copied mechanically from
 * prototype/app/morashiter-data.ts into data/launch-incidents.json). Safe to run more than once.
 */
class LaunchIncidentsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode((string) file_get_contents(database_path('seeders/data/launch-incidents.json')), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                if (Incident::query()->where('display_code', $row['id'])->exists()) {
                    continue;
                }
                $incident = Incident::query()->create(['display_code' => $row['id'], 'origin' => 'seed']);
                $fields = ['incident_id' => $incident->id, 'revision_number' => 1, 'status' => 'approved', 'approval_method' => 'manual', 'approved_at' => now()];
                foreach (IncidentRevision::PUBLIC_FIELDS as $siteKey => $column) {
                    $fields[$column] = $row[$siteKey];
                }
                IncidentRevision::query()->create($fields);
            }
        });
    }
}
