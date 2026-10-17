<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Everyone signs in to /admin, the Command Centre. Who may not see it (site visit dispatchers and
 * technicians…) is sent on to the first page of their own menu — never a 403.
 */
class HomePageTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_people_without_the_command_centre_land_on_their_own_page(): void
    {
        Role::findOrCreate('Hand-made Shifting Dispatcher', 'web')->syncPermissions([
            'ticket.view', 'ticket.work', 'visit.view', 'visit.create', 'visit.dispatch', 'visit.dispatch_shifting', 'queue.view', 'queue.work_visit_dispatch',
        ]);

        foreach (['Site Visit Dispatcher', 'Shifting / Relocation Dispatcher', 'Hand-made Shifting Dispatcher', 'Site Visit Technician'] as $role) {
            $this->actingAs($this->makeUser($role, 'DISPATCH'));

            $location = $this->get('/admin')->assertRedirect()->headers->get('Location');
            $this->assertStringNotContainsString('/admin/login', $location, $role);
            $this->get($location)->assertOk();
        }

        // The Command Centre itself for those who may see it.
        $this->actingAs($this->makeUser('Super Admin', 'MGMT'))->get('/admin')->assertOk()->assertSee('Command Centre');
    }

    public function test_an_account_with_no_pages_is_told_so(): void
    {
        Role::findOrCreate('Empty role', 'web');
        $this->actingAs($this->makeUser('Empty role', 'CX'))->get('/admin')->assertForbidden();
    }
}
