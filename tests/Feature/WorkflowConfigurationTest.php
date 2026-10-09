<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Pages\WorkflowConfiguration;
use App\Models\MajorOutageType;
use App\Models\QueueDefinition;
use App\Services\Sla\DeskSla;
use App\Services\Workflows\WorkflowConfig;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Workflow configuration, stage 1: the flows step by step, and what an administrator can change
 * from the screen — who works a step, how it is handed out, the times, and the switches that add
 * or drop a step — without touching tickets that already passed.
 */
class WorkflowConfigurationTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
    }

    private function admin()
    {
        return $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
    }

    public function test_the_screen_needs_its_own_permission(): void
    {
        $this->actingAs($this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']));
        $this->assertFalse(WorkflowConfiguration::canAccess());
        $this->get(WorkflowConfiguration::getUrl())->assertForbidden();

        $this->actingAs($this->admin());
        $this->assertTrue(WorkflowConfiguration::canAccess());
        $this->get(WorkflowConfiguration::getUrl())->assertOk()->assertSee('Workflow Configuration');

        // An installation in use: the roles that administer the settings get it when it arrives.
        Permission::query()->where('name', 'workflow.configure')->delete();
        Role::findByName('NOC Manager')->givePermissionTo('config.admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (require database_path('migrations/2026_10_10_100002_add_workflow_configure_permission.php'))->up();

        $this->assertTrue(Role::findByName('NOC Manager')->hasPermissionTo('workflow.configure'));
        $this->assertFalse(Role::findByName('NOC Tier I')->hasPermissionTo('workflow.configure'));
    }

    public function test_every_flow_is_laid_out_step_by_step_with_its_configuration(): void
    {
        $config = app(WorkflowConfig::class);

        $this->assertSame(
            ['outage', 'system', 'planned', 'field_maintenance', 'mobility', 'mobility_vendor', 'cx_ticket', 'site_visit'],
            array_keys($config->flows()),
        );

        $steps = collect($config->steps('outage'))->keyBy('name');
        $this->assertSame(['Reported', 'NOC review', 'Dispatch', 'Field work', 'Support technician', 'NOC restore confirmation', 'Customer call-back'], $steps->keys()->all());
        $this->assertSame(['noc_review', 'Automatic FIFO', 10, 20, 'NOC'], [
            $steps['NOC review']['desk'], $steps['NOC review']['mode'], $steps['NOC review']['ack'], $steps['NOC review']['target'], $steps['NOC review']['department'],
        ]);
        $this->assertContains('NOC Tier II', $steps['NOC review']['roles']);
        $this->assertNotContains('CX Agent', $steps['NOC review']['roles']);
        $this->assertContains('CX Agent', $steps['Reported']['roles'], 'a step without a queue is done by whoever holds its permission');
        $this->assertNull($steps['Reported']['desk']);
        $this->assertSame('alongside', $steps['Support technician']['kind']);
        $this->assertSame('branch', $steps['Customer call-back']['kind']);
        $this->assertGreaterThan(0, collect($steps['NOC review']['events'])->sum('rules'), 'the notification rules of the step are counted');

        // Every step with a desk names a real desk, and every flow has steps.
        foreach (array_keys($config->flows()) as $flow) {
            $this->assertNotEmpty($config->steps($flow), $flow);
        }

        $this->actingAs($this->admin());
        Livewire::test(WorkflowConfiguration::class)
            ->assertSee('NOC review')->assertSee('NOC restore confirmation')->assertSee('20 min')->assertSee('Automatic FIFO')
            ->assertSee('On the field within (minutes)')
            ->set('flow', 'site_visit')
            ->assertSee('Dispatch and verification')->assertSee('Confirmation with the customer')->assertSee('Desk routes')
            ->assertDontSee('NOC restore confirmation')
            ->set('flow', 'no-such-flow')
            ->assertSee('NOC restore confirmation');
    }

    public function test_a_step_is_changed_from_the_screen_and_only_new_arrivals_follow_it(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $team = $this->makeTeam('NOC night shift', 'NOC');

        $before = $this->reportOutage($agent);
        $this->assertSame($noc->id, $before->current_assignee_id);
        $this->assertSame(20, $before->queueEntries()->firstOrFail()->sla_minutes);

        $this->actingAs($this->admin());
        Livewire::test(WorkflowConfiguration::class)
            ->callAction(TestAction::make('editStep')->arguments(['desk' => 'noc_review']), data: [
                'assignment_mode' => 'manual', 'team_id' => $team->id, 'ack_timeout_minutes' => 5, 'escalate_after_attempts' => 2, 'target_minutes' => 25,
            ])
            ->assertHasNoActionErrors()
            ->assertSee('Manual (a manager assigns)')->assertSee('25 min')->assertSee('NOC night shift');

        $queue = QueueDefinition::query()->where('queue_type', 'noc_review')->sole();
        $this->assertSame(['manual', $team->id, 5, 2], [$queue->assignment_mode, $queue->team_id, $queue->ack_timeout_minutes, $queue->escalate_after_attempts]);
        $this->assertSame(25, app(DeskSla::class)->minutesFor(QueueType::NocReview));
        $this->assertSame(15, app(DeskSla::class)->minutesFor(QueueType::CxTier2), 'the other desks keep their targets');

        // The ticket that was already at the step keeps what applied to it…
        $this->assertSame(20, $before->queueEntries()->firstOrFail()->sla_minutes);
        $this->assertSame($noc->id, $before->refresh()->current_assignee_id);

        // …the next one follows the new configuration: 25 minutes, and it waits for a manager.
        $after = $this->reportOutage($agent);
        $this->assertSame(25, $after->queueEntries()->firstOrFail()->sla_minutes);
        $this->assertNull($after->current_assignee_id);

        // The change is on the page's history, with who made it.
        $history = app(WorkflowConfig::class)->history();
        $this->assertTrue($history->contains(fn (array $entry) => str_contains(implode(' ', $entry['changes']), 'assignment_mode: auto → manual')));
        $this->assertTrue($history->contains(fn (array $entry) => str_starts_with($entry['what'], 'Setting desk_sla.minutes')));
    }

    public function test_switches_add_or_drop_a_step(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->makeUser('NOC Tier II', 'NOC');
        $this->makeUser('Infrastructure Dispatcher', 'INFRA');
        $config = app(WorkflowConfig::class);
        $network = MajorOutageType::query()->where('code', MajorOutageType::NETWORK)->firstOrFail();
        $key = "major.{$network->id}.requires_noc_confirmation";

        $switches = collect($config->switches('outage'))->keyBy('key');
        $this->assertTrue($switches[$key]['value']);
        $this->assertSame(30, $switches['desk_sla.field_minutes']['value']);
        $this->assertSame(QueueType::NocReview, $this->reportOutage($agent)->current_queue);

        // From the screen: outages no longer wait for the NOC, and the field has 45 minutes.
        $this->actingAs($this->admin());
        Livewire::test(WorkflowConfiguration::class)
            ->callAction('editSwitches', data: ['s'.md5($key) => false, 's'.md5('desk_sla.field_minutes') => 45, 's'.md5('photos.outage_required') => true])
            ->assertHasNoActionErrors();

        $this->assertFalse($network->refresh()->requires_noc_confirmation);
        $this->assertSame(45, app(Settings::class)->int('desk_sla.field_minutes'));

        $outage = $this->reportOutage($agent);
        $this->assertSame(QueueType::Infrastructure, $outage->current_queue, 'the NOC review step is skipped');
        $this->assertTrue($outage->status->isCode(StatusCode::AssignedToInfrastructure));
        $this->assertSame(45, (int) $outage->created_at->diffInMinutes($outage->field_due_at));

        // Only the switches of the flow are accepted, whatever is sent.
        $config->saveSwitches('site_visit', ['fifo.enabled' => false, 'ticketing.confirm_closure' => false, "major.{$network->id}.requires_infrastructure" => false], $this->admin());
        $this->assertTrue(app(Settings::class)->bool('fifo.enabled'));
        $this->assertFalse(app(Settings::class)->bool('ticketing.confirm_closure'));
        $this->assertTrue($network->refresh()->requires_infrastructure);
    }
}
