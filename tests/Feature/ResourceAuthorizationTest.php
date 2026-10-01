<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Fdts\FdtResource;
use App\Filament\Resources\NotificationLogs\NotificationLogResource;
use App\Filament\Resources\Olts\OltResource;
use App\Filament\Resources\Olts\Pages\ManageOlts;
use App\Filament\Resources\Outages\OutageResource;
use App\Filament\Resources\Ports\PortResource;
use App\Filament\Resources\Slots\SlotResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Olt;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Filament authorises Create / Edit through get*AuthorizationResponse(), which allows the action
 * when a model has no policy. Configuration resources are permission-based, so viewing a list
 * must never imply being able to change it.
 */
class ResourceAuthorizationTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_viewers_cannot_create_or_edit_any_configuration_resource(): void
    {
        foreach (['CX Agent', 'CX Tier II', 'CX Team Leader', 'NOC Tier I', 'Outage Technician'] as $role) {
            $this->actingAs($this->makeUser($role, 'CX', attributes: ['availability_status' => 'offline']));

            foreach (Filament::getPanel('admin')->getResources() as $resource) {
                if ($resource === OutageResource::class) {
                    continue; // tickets have their own policy and workflow
                }

                $model = new ($resource::getModel());
                $this->assertFalse($resource::getCreateAuthorizationResponse()->allowed(), "{$role} must not create via ".class_basename($resource));
                $this->assertFalse($resource::getDeleteAuthorizationResponse($model)->allowed(), "{$role} must not delete via ".class_basename($resource));

                if ($resource !== UserResource::class) {
                    $this->assertFalse($resource::getEditAuthorizationResponse($model)->allowed(), "{$role} must not edit via ".class_basename($resource));
                }
            }
        }
    }

    public function test_cx_can_view_topology_but_not_change_it(): void
    {
        $olt = $this->makeOlt('KIIRA');
        $this->actingAs($this->makeUser('CX Agent', 'CX'));

        foreach ([OltResource::class, FdtResource::class, SlotResource::class, PortResource::class] as $resource) {
            $this->get($resource::getUrl())->assertOk();
        }

        Livewire::test(ManageOlts::class)
            ->assertActionHidden(TestAction::make('create'))
            ->assertActionHidden(TestAction::make('importTopology'))
            ->assertActionHidden(TestAction::make('edit')->table($olt));

        $this->assertSame(1, Olt::query()->count());
    }

    public function test_topology_managers_can_still_create_and_edit(): void
    {
        $olt = $this->makeOlt('KIIRA');
        $this->actingAs($this->makeUser('Infrastructure Manager', 'INFRA', attributes: ['availability_status' => 'offline']));

        Livewire::test(ManageOlts::class)
            ->assertActionVisible(TestAction::make('create'))
            ->callAction(TestAction::make('create'), ['name' => 'BAHAI', 'is_active' => true])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('edit')->table($olt), ['name' => 'KIIRA 2'])
            ->assertHasNoFormErrors();

        $this->assertSame(['BAHAI', 'KIIRA 2'], Olt::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_workforce_managers_edit_users_but_only_user_admins_create_them(): void
    {
        $lead = $this->makeUser('NOC Team Leader', 'NOC', attributes: ['availability_status' => 'offline']); // workforce.manage, not users.manage
        $this->actingAs($lead);

        $this->get(UserResource::getUrl())->assertOk();
        $this->assertFalse(UserResource::getCreateAuthorizationResponse()->allowed());
        $this->assertTrue(UserResource::getEditAuthorizationResponse($lead)->allowed(), 'team / workforce fields');

        $this->actingAs($this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']));
        $this->assertTrue(UserResource::getCreateAuthorizationResponse()->allowed());
    }

    public function test_logs_are_read_only_for_everyone(): void
    {
        $this->actingAs($this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']));

        foreach ([AuditLogResource::class, NotificationLogResource::class] as $resource) {
            $model = new ($resource::getModel());
            $this->assertTrue($resource::getViewAnyAuthorizationResponse()->allowed());
            $this->assertFalse($resource::getCreateAuthorizationResponse()->allowed());
            $this->assertFalse($resource::getEditAuthorizationResponse($model)->allowed());
            $this->assertFalse($resource::getDeleteAuthorizationResponse($model)->allowed());
        }
    }
}
