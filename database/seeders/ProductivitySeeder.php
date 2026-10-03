<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The staff productivity e-mails (hourly, daily, weekly, monthly), for a system that already holds
 * data. Create-only and inactive until recipients are set:
 *
 *   php artisan db:seed --class=ProductivitySeeder --force
 *
 * The productivity report itself needs no seeding (Reports → Staff Productivity).
 */
class ProductivitySeeder extends Seeder
{
    public function run(): void
    {
        app(EmailTemplateSeeder::class)->productivity();
    }
}
