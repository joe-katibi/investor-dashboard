<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data and the network topology (OLT → FDT → Slot → Port) are always seeded
     * (idempotent). Demo users are only seeded outside production; create the first real
     * administrator with `php artisan oms:create-admin`.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ReferenceDataSeeder::class,
            SystemRoutingSeeder::class,
            TopologySeeder::class,
            MobilityTopologySeeder::class,
        ]);

        if (! app()->isProduction() && env('OMS_SEED_DEMO', true)) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
