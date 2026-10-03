<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe reference data from the specification, in dependency order. Every seeder
 * is idempotent (updateOrCreate on stable codes / names) and can also be run on its own:
 *
 *   php artisan db:seed --class=PrioritySeeder --force
 */
class ReferenceDataSeeder extends Seeder
{
    public const SEEDERS = [
        DepartmentSeeder::class,
        VerticalSeeder::class,
        ServiceSeeder::class,
        MajorOutageTypeSeeder::class,
        OutageTypeSeeder::class,
        OutageStatusSeeder::class,
        PrioritySeeder::class,
        ImpactRuleSeeder::class,
        SlaPolicySeeder::class,
        QueueDefinitionSeeder::class,
        NotificationRuleSeeder::class,
        EmailTemplateSeeder::class,
        MaintenanceTemplateSeeder::class,
        VendorSeeder::class,
        VendorTemplateSeeder::class,
        TicketCatalogSeeder::class,
    ];

    public function run(): void
    {
        $this->call(self::SEEDERS);
    }
}
