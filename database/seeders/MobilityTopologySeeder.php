<?php

namespace Database\Seeders;

use App\Services\Topology\MobilityTopologyImporter;
use Illuminate\Database\Seeder;

/**
 * 4G topology from the NOC workbook: eNodeB sites and transmission nodes (RSG → ASG → CSG → SSG),
 * bundled as database/seeders/data/4g-enodebs.csv and 4g-transmission.csv (one file per sheet).
 *
 *   php artisan db:seed --class=MobilityTopologySeeder --force
 *
 * Safe to re-run: sites are matched on their ID and nodes on their code; nothing is duplicated or
 * removed. To load a newer workbook without a code change, put it at
 * storage/app/private/topology/4g-topology.xlsx — it takes precedence — or use
 * `php artisan oms:import-4g-topology` / 4G NOC → eNodeB Sites → Import 4G topology.
 *
 * Runs with `php artisan db:seed` (DatabaseSeeder), after VendorSeeder so sites link to their
 * tower company. Deliberately not part of ReferenceDataSeeder.
 */
class MobilityTopologySeeder extends Seeder
{
    /** Override the file locations (tests). @var list<string>|null */
    public static ?array $paths = null;

    /** @return list<string> sites first, so SSG rows can link to them */
    public static function bundledPaths(): array
    {
        return [database_path('seeders/data/4g-enodebs.csv'), database_path('seeders/data/4g-transmission.csv')];
    }

    public function run(MobilityTopologyImporter $importer): void
    {
        $workbook = storage_path('app/private/topology/4g-topology.xlsx');
        $paths = static::$paths ?? (is_file($workbook) ? [$workbook] : array_values(array_filter(static::bundledPaths(), 'is_file')));

        if ($paths === []) {
            $this->command?->warn('No 4G topology found (database/seeders/data/4g-enodebs.csv, 4g-transmission.csv).');

            return;
        }

        foreach ($paths as $path) {
            $report = $importer->import($path);

            if ($report['errors'] !== []) {
                $this->command?->error('4G topology not imported from '.basename($path).': '.implode(' | ', array_slice($report['errors'], 0, 10)));

                continue;
            }

            $this->command?->info('4G topology ('.basename($path)."): {$report['sites']['created']} sites and {$report['nodes']['created']} transmission nodes created, "
                .($report['sites']['updated'] + $report['nodes']['updated']).' updated.');
        }
    }
}
