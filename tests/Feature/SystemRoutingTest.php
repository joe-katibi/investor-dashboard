<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use Database\Seeders\SystemRoutingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * System outages are routed by the affected system: Savbill and Grafana to the CRM Managers,
 * CPaaS to the CPaaS Manager, every other system to the IT desk.
 */
class SystemRoutingTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->seed(SystemRoutingSeeder::class);
        Mail::fake();
        $this->workflow = app(OutageWorkflow::class);
    }

    private function systemOutage(User $by, string ...$systems): Outage
    {
        return $this->reportOutage($by, [
            'title' => implode(' / ', $systems).' down',
            'outage_type_id' => OutageType::query()->where('code', 'system')->value('id'),
            'customers_affected' => 0,
            'service_ids' => Service::query()->whereIn('name', $systems)->pluck('id')->all(),
        ]);
    }

    private function owner(string $role, string $team): User
    {
        return $this->makeUser($role, 'MGMT', [Team::query()->where('name', $team)->firstOrFail()]);
    }

    public function test_the_systems_teams_and_roles_are_added_without_touching_what_exists(): void
    {
        $crm = Team::query()->where('name', 'CRM Managers')->firstOrFail();
        $cpaas = Team::query()->where('name', 'CPaaS Manager')->firstOrFail();

        $this->assertSame($crm->id, Service::query()->where('name', 'Billing (Savbill)')->value('review_team_id'));
        $this->assertSame($crm->id, Service::query()->where('name', 'Grafana')->value('review_team_id'));
        $this->assertSame($cpaas->id, Service::query()->where('name', 'CPaaS')->value('review_team_id'));

        foreach (['Sav-Money', 'Customer App', 'Mail'] as $system) {
            $this->assertNull(Service::query()->where('name', $system)->firstOrFail()->review_team_id, "{$system} stays with IT");
        }

        foreach (SystemRoutingSeeder::ROLES as $role) {
            $this->assertTrue(Role::findByName($role)->hasPermissionTo('queue.work_it'));
            $this->assertFalse(Role::findByName($role)->hasPermissionTo('outage.confirm'), 'a system owner is not the NOC');
        }

        // An administrator sends Grafana back to IT: running the seeder again keeps that.
        Service::query()->where('name', 'Grafana')->update(['review_team_id' => null]);
        $services = Service::query()->count();
        $this->seed(SystemRoutingSeeder::class);

        $this->assertNull(Service::query()->where('name', 'Grafana')->value('review_team_id'));
        $this->assertSame($services, Service::query()->count());
        $this->assertSame(1, Team::query()->where('name', 'CRM Managers')->count());
    }

    public function test_savbill_and_grafana_go_to_the_crm_managers_and_cpaas_to_its_manager(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $it = $this->makeUser('IT Engineer', 'IT');
        $crm = $this->owner('CRM Manager', 'CRM Managers');
        $cpaas = $this->owner('CPaaS Manager', 'CPaaS Manager');

        $savbill = $this->systemOutage($agent, 'Billing (Savbill)');
        $grafana = $this->systemOutage($agent, 'Grafana');
        $messaging = $this->systemOutage($agent, 'CPaaS');
        $mail = $this->systemOutage($agent, 'Mail');
        $plain = $this->systemOutage($agent);

        $this->assertSame($crm->id, $savbill->current_assignee_id);
        $this->assertSame($crm->id, $grafana->current_assignee_id);
        $this->assertSame($cpaas->id, $messaging->current_assignee_id);
        $this->assertSame($it->id, $mail->current_assignee_id, 'a system without owners stays with the IT desk');
        $this->assertSame($it->id, $plain->current_assignee_id);

        $this->assertSame(QueueType::ItReview, $savbill->current_queue);
        $this->assertTrue($savbill->status->isCode(StatusCode::ItReview));
        $this->assertStringContainsString('routed to CRM Managers', $savbill->updates()->pluck('body')->implode(' | '));

        // The owner works it like IT would; the IT desk cannot take it from them.
        $this->assertTrue($crm->can('itReview', $savbill));
        Auth::setUser($crm);
        $resolved = $this->workflow->itResolve($savbill, $crm, 'Savbill database restarted; invoices are generated again.');
        $this->assertTrue($resolved->is_closed);
    }

    public function test_a_system_with_owners_waits_for_them_and_comes_back_to_them_from_noc(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $it = $this->makeUser('IT Engineer', 'IT');
        $noc = $this->makeUser('NOC Tier II', 'NOC');

        // Nobody of the team is there yet: it waits in the queue, IT does not get it.
        $outage = $this->systemOutage($agent, 'CPaaS', 'Mail');
        $this->assertNull($outage->current_assignee_id);
        $this->assertSame(QueueType::ItReview, $outage->current_queue);

        $cpaas = $this->owner('CPaaS Manager', 'CPaaS Manager');
        app(FifoAssignmentEngine::class)->process(QueueType::ItReview);
        $this->assertSame($cpaas->id, $outage->refresh()->current_assignee_id);

        Auth::setUser($cpaas);
        $outage = $this->workflow->itEscalateToNoc($outage, $cpaas, 'SMS gateway unreachable from the core');
        $this->assertSame($noc->id, $outage->current_assignee_id);

        Auth::setUser($noc);
        $outage = $this->workflow->nocReturnToIt($outage, $noc, 'Route to the gateway restored');

        $this->assertSame(QueueType::ItConfirmation, $outage->current_queue);
        $this->assertSame($cpaas->id, $outage->current_assignee_id, 'the confirmation goes back to the system owner, not to IT');
        $this->assertNotSame($it->id, $outage->current_assignee_id);
    }
}
