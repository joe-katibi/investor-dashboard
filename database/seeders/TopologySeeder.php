<?php

namespace Database\Seeders;

use App\Services\Topology\TopologyImporter;
use Illuminate\Database\Seeder;

/**
 * Network topology: OLT → FDT → Slot → Port, from the bundled mapping
 * database/seeders/data/topology.csv (columns OLT, FDT, SLOT, PORT — one row per OLT port).
 *
 *   php artisan db:seed --class=TopologySeeder --force
 *
 * Safe to re-run: existing items are reused and only missing ones are created; nothing is removed.
 * To load a newer mapping without a code change, put it at
 * storage/app/private/topology/mapping.xlsx (or mapping.csv) — it takes precedence — or use
 * `php artisan oms:import-topology` / Configuration → OLTs → Import topology.
 *
 * Runs with `php artisan db:seed` (DatabaseSeeder). Deliberately not part of ReferenceDataSeeder.
 */
class TopologySeeder extends Seeder
{
    /** Override the file location (tests). */
    public static ?string $path = null;

    public static function bundledPath(): string
    {
        return database_path('seeders/data/topology.csv');
    }

    public function run(TopologyImporter $importer): void
    {
        $path = static::$path ?? collect([
            storage_path('app/private/topology/mapping.xlsx'),
            storage_path('app/private/topology/mapping.csv'),
            static::bundledPath(),
        ])->first(fn (string $file) => is_file($file));

        if (! $path) {
            $this->command?->warn('No topology mapping found (database/seeders/data/topology.csv).');

            return;
        }

        $report = $importer->import($path);

        if ($report['errors'] !== []) {
            $this->command?->error('Topology not imported: '.implode(' | ', array_slice($report['errors'], 0, 10)));

            return;
        }

        $c = $report['created'];
        $this->command?->info("Topology: {$report['rows']} rows — created {$c['olts']} OLTs, {$c['fdts']} FDTs, {$c['slots']} slots, {$c['ports']} ports.");
    }
}
