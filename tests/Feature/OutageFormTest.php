<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\QueueType;
use App\Enums\TeamType;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Outages\OutageForm;
use App\Filament\Resources\Outages\Pages\CreateOutage;
use App\Filament\Resources\Outages\Pages\ViewOutage;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Fdt;
use App\Models\MajorOutageType;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\Port;
use App\Models\Slot;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** Outage create form (topology "ALL" selection) and the dispatch modal. */
class OutageFormTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
    }

    /** Two OLTs, each with two FDTs, each with two slots, each with two ports; plus one inactive OLT. */
    private function buildTopology(): void
    {
        foreach (['OLT-A', 'OLT-B'] as $oltName) {
            $olt = $this->makeOlt($oltName);
            foreach ([1, 2] as $f) {
                $fdt = Fdt::query()->create(['olt_id' => $olt->id, 'name' => "FDT-{$f}", 'is_active' => true]);
                foreach ([1, 2] as $s) {
                    $slot = Slot::query()->create(['fdt_id' => $fdt->id, 'name' => "S{$s}", 'is_active' => true]);
                    foreach ([1, 2] as $p) {
                        Port::query()->create(['slot_id' => $slot->id, 'name' => "P{$p}", 'is_active' => true]);
                    }
                }
            }
        }
        Olt::query()->create(['name' => 'OLT-OLD', 'is_active' => false]);
    }

    public function test_all_option_selects_every_item_at_each_topology_level(): void
    {
        $this->buildTopology();
        $this->makeUser('NOC Tier I', 'NOC');
        $this->actingAs($this->makeUser('CX Agent', 'CX'));

        $activeOlts = Olt::query()->where('is_active', true)->pluck('id')->all();

        // PostgreSQL rejects "all" in an id comparison (SQLite / MySQL cast it), so check the bindings directly.
        $leaked = [];
        DB::listen(function (QueryExecuted $query) use (&$leaked): void {
            if (in_array(OutageForm::ALL, $query->bindings, true)) {
                $leaked[] = $query->sql;
            }
        });

        Livewire::test(CreateOutage::class)
            ->fillForm([
                'title' => 'Area-wide fibre outage',
                'major_outage_type_id' => MajorOutageType::query()->where('code', MajorOutageType::NETWORK)->value('id'),
            ])
            ->fillForm([
                'outage_type_id' => OutageType::query()->where('code', 'regular')->value('id'),
                'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'),
                'customers_affected' => 150,
            ])
            // set() sends the whole array like the browser does (fillForm trims arrays to the filled length).
            ->set('data.olt_ids', [OutageForm::ALL])
            ->assertSchemaStateSet(['olt_ids' => $activeOlts])
            ->set('data.fdt_ids', [OutageForm::ALL])
            ->assertSchemaStateSet(['fdt_ids' => Fdt::query()->pluck('id')->all()])
            ->set('data.slot_ids', [OutageForm::ALL])
            ->assertSchemaStateSet(['slot_ids' => Slot::query()->pluck('id')->all()])
            ->set('data.port_ids', [OutageForm::ALL])
            ->assertSchemaStateSet(['port_ids' => Port::query()->pluck('id')->all()])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([], $leaked, 'the ALL sentinel must never reach a query');

        $outage = Outage::query()->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing($activeOlts, $outage->olts()->pluck('olts.id')->all(), 'inactive OLTs are not included');
        $this->assertSame(4, $outage->fdts()->count());
        $this->assertSame(8, $outage->slots()->count());
        $this->assertSame(16, $outage->ports()->count());
    }

    public function test_all_option_is_scoped_to_the_selected_parents(): void
    {
        $this->buildTopology();
        $oltA = Olt::query()->where('name', 'OLT-A')->value('id');

        $options = OutageForm::fdtOptions([$oltA]);
        $this->assertCount(2, $options);
        $this->assertSame([], OutageForm::fdtOptions([]));
        $this->assertSame([3, 5], OutageForm::ids(['3', OutageForm::ALL, 5, 'x']));
    }

    public function test_assign_override_fifo_opens_and_reassigns(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $first = $this->makeUser('NOC Tier I', 'NOC');
        $outage = $this->reportOutage($agent);
        $this->assertSame($first->id, $outage->current_assignee_id);

        $second = $this->makeUser('NOC Tier II', 'NOC');
        $lead = $this->makeUser('NOC Team Leader', 'NOC', attributes: ['availability_status' => 'offline']); // queue.override
        $this->actingAs($lead);

        // The queue Select is enum-backed: its state comes back as a QueueType, in the options closure and on submit.
        Livewire::test(ViewOutage::class, ['record' => $outage->getRouteKey()])
            ->callAction(TestAction::make('reassign'), [
                'queue_type' => QueueType::NocReview->value,
                'user_id' => $second->id,
                'reason' => 'Shift handover',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame($second->id, $outage->refresh()->current_assignee_id);
    }

    public function test_set_availability_on_the_users_screen(): void
    {
        $tech = $this->makeUser('Outage Technician', 'INFRA');
        $this->actingAs($this->makeUser('NOC Team Leader', 'NOC', attributes: ['availability_status' => 'offline'])); // workforce.manage

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('availability')->table($tech), ['status' => AvailabilityStatus::OnBreak->value, 'reason' => 'Lunch'])
            ->assertHasNoFormErrors();

        $this->assertSame(AvailabilityStatus::OnBreak, $tech->refresh()->availability_status);
    }

    public function test_dispatch_modal_opens_with_team_type_prefilled(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);

        $outage = $this->reportOutage($agent);
        Auth::setUser($noc);
        $outage = app(OutageWorkflow::class)->confirmByNoc($outage, $noc);

        $this->actingAs($dispatcher);

        Livewire::test(ViewOutage::class, ['record' => $outage->getRouteKey()])
            ->mountAction(TestAction::make('dispatch'))
            ->assertActionMounted(TestAction::make('dispatch'));

        // The Select hands the enum (not its value) to the options closure once hydrated.
        $this->assertSame([$team->id => 'Field Ops'], OutageActions::teamOptions(TeamType::InHouse));
        $this->assertSame([$team->id => 'Field Ops'], OutageActions::teamOptions('in_house'));
        $this->assertSame([], OutageActions::teamOptions(TeamType::Outsource));
    }
}
