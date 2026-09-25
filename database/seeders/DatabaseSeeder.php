<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data is always seeded (idempotent). Demo users/outages are only seeded
     * outside production; create the first real administrator with `php artisan oms:create-admin`.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        if (! app()->isProduction() && env('OMS_SEED_DEMO', true)) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
