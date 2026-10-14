<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\MajorOutageType;
use Illuminate\Database\Seeder;

/**
 * Everything the customer ticketing / site visit module needs, and nothing else — for a
 * production system that already holds configured data:
 *
 *   php artisan db:seed --class=TicketingSeeder --force
 *
 * It only CREATES what is missing; no existing status, type, queue, template, department or
 * record is updated. Safe to run again. The one exception is described on
 * RolesAndPermissionsSeeder::ticketing(): the first time, the existing CX / NOC / Dispatch /
 * technician roles get their ticketing permissions.
 *
 * Creates: the ticketing permissions and roles · the Billing, Commercial, Design and Access
 * departments · the "Customer Ticket" major type with the Customer Ticket and Site Visit types
 * and their statuses · one FIFO queue per desk · visit types (INS / WiE / SUP / SHI) · issue
 * categories and subcategories with their routing · reasons for delay and final reasons · the
 * desk-to-desk routes · the four ticket e-mail templates (inactive until recipients are set).
 *
 * Regions, customers and "Ticket Settings" are not seeded: add regions and import customers on
 * screen; the settings start from built-in defaults (SLA clock 07:00–18:00, 3-hour target…).
 */
class TicketingSeeder extends Seeder
{
    public function run(): void
    {
        app(RolesAndPermissionsSeeder::class)->ticketing();

        $free = fn (string $model, string $name) => $model::query()->where('name', $name)->exists() ? $name.' (ticketing)' : $name;

        foreach (DepartmentSeeder::DEPARTMENTS as [$code, $name, $description]) {
            if (in_array($code, DepartmentSeeder::TICKETING, true)) {
                Department::query()->firstOrCreate(['code' => $code], ['name' => $free(Department::class, $name), 'description' => $description, 'is_active' => true]);
            }
        }

        MajorOutageType::query()->firstOrCreate(['code' => MajorOutageType::CUSTOMER], [
            'name' => $free(MajorOutageType::class, 'Customer Ticket'),
            'description' => 'Customer Care: customer tickets and site visits',
            'requires_noc_confirmation' => false,
            'requires_infrastructure' => false,
            'uses_topology' => false,
            'requires_it_review' => false,
            'sort_order' => 5,
            'is_active' => true,
        ]);

        app(OutageTypeSeeder::class)->ticketing();
        app(OutageStatusSeeder::class)->ticketing();
        app(QueueDefinitionSeeder::class)->ticketing();
        app(EmailTemplateSeeder::class)->ticketing();

        $this->call(TicketCatalogSeeder::class);
        // 4G customer tickets: their catalogue, desks, roles and vendor e-mails.
        $this->call(FourGTicketingSeeder::class);
    }
}
