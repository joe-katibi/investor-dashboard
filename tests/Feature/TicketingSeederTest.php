<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Models\DeskRoute;
use App\Models\EmailTemplate;
use App\Models\OutageStatus;
use App\Models\QueueDefinition;
use App\Models\TicketSubcategory;
use Database\Seeders\TicketingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** TicketingSeeder only creates what is missing: safe on a production system with tuned data, and safe to run again. */
class TicketingSeederTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    public function test_the_ticketing_seeder_creates_only_what_is_missing(): void
    {
        $this->seedReference();

        // A system that is already running: some ticket configuration edited, and the ticket permissions not there yet.
        TicketSubcategory::query()->where('name', 'Slow Speeds')->update(['first_desk' => QueueType::TicketNoc->value]);
        OutageStatus::query()->where('code', StatusCode::VisitOpen->value)->update(['name' => 'Waiting for dispatch']);
        DeskRoute::query()->where('from_desk', 'billing')->where('to_desk', 'commercial')->delete();
        Permission::query()->where('name', 'ticket.view')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Role::findByName('CX Tier II')->hasPermissionTo('ticket.work') && Role::findByName('CX Tier II')->permissions()->where('name', 'ticket.view')->exists());

        $counts = fn () => [
            QueueDefinition::query()->count(), EmailTemplate::query()->count(), TicketSubcategory::query()->count(), OutageStatus::query()->count(),
        ];

        $this->seed(TicketingSeeder::class);
        $after = $counts();
        $this->seed(TicketingSeeder::class);

        $this->assertSame($after, $counts(), 'running it again creates nothing');
        $this->assertSame(QueueType::TicketNoc, TicketSubcategory::query()->where('name', 'Slow Speeds')->firstOrFail()->first_desk, 'edits are kept');
        $this->assertSame('Waiting for dispatch', OutageStatus::forCode(StatusCode::VisitOpen)->name);
        $this->assertTrue(DeskRoute::query()->where('from_desk', 'billing')->where('to_desk', 'commercial')->exists(), 'a missing route is created');

        // The first time the ticket permissions appear, the existing CX role gets them.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue(Role::findByName('CX Tier II')->hasPermissionTo('ticket.view'));
        $this->assertTrue(Role::findByName('CX Tier II')->hasPermissionTo(QueueType::CxTier2->defaultPermission()));
        $this->assertTrue(Role::findByName('Site Visit Dispatcher')->hasPermissionTo('visit.dispatch'));
        $this->assertSame(0, QueueDefinition::query()->where('queue_type', QueueType::VisitTechnician->value)->value('ack_timeout_minutes'));
    }
}
