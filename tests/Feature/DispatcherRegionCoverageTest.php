<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Models\Outage;
use App\Models\Region;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Fifo\EligibilityEvaluator;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** "Restrict dispatchers to their regions' OLT coverage": off, dispatchers get work anywhere; on, only on OLTs of their regions. */
class DispatcherRegionCoverageTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    /** @return array<string, array{label: string, passed: bool, detail: string}> */
    private function checks(Outage $outage, QueueType $queue, User $user): array
    {
        $evaluator = app(EligibilityEvaluator::class);
        $evaluator->beginRun();

        return $evaluator->checks($outage->openQueueEntries()->where('queue_type', $queue->value)->firstOrFail(), $user->load('regions'));
    }

    public function test_dispatchers_only_get_work_on_olts_of_their_regions_when_switched_on(): void
    {
        $this->seedReference();
        Mail::fake();
        [$north, $south] = [Region::query()->create(['name' => 'North', 'is_active' => true]), Region::query()->create(['name' => 'South', 'is_active' => true])];
        $olt = $this->makeOlt('KANSANGA');

        // An FTTH outage at Infrastructure dispatch…
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $outage = $this->reportOutage($this->makeUser('CX Agent', 'CX'), ['olt_ids' => [$olt->id]]);
        Auth::setUser($noc);
        $outage = app(OutageWorkflow::class)->confirmByNoc($outage, $noc);

        // …and a site visit raised while its OLT had no region yet.
        $siteDispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH', attributes: ['availability_status' => 'offline']);
        $visit = app(OutageWorkflow::class)->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => 'SF1', 'customer_name' => 'SF1', 'calling_number' => '0772000010', 'address' => 'Kansanga', 'olt_id' => $olt->id,
        ], $siteDispatcher);
        $this->assertNull($visit->ticket->region_id);
        $olt->forceFill(['region_id' => $south->id])->save();

        $northInfra = $this->makeUser('Infrastructure Dispatcher', 'INFRA');
        $northInfra->regions()->sync([$north->id]);
        $southInfra = $this->makeUser('Infrastructure Dispatcher', 'INFRA');
        $southInfra->regions()->sync([$south->id]);
        $anyInfra = $this->makeUser('Infrastructure Dispatcher', 'INFRA');
        $northVisits = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $northVisits->regions()->sync([$north->id]);

        // Off (the default): no such check.
        $this->assertArrayNotHasKey('region_coverage', $this->checks($outage, QueueType::Infrastructure, $northInfra));

        app(Settings::class)->set(['fifo.dispatcher_region_coverage' => true]);

        $this->assertFalse($this->checks($outage, QueueType::Infrastructure, $northInfra)['region_coverage']['passed']);
        $this->assertTrue($this->checks($outage, QueueType::Infrastructure, $southInfra)['region_coverage']['passed']);
        $this->assertTrue($this->checks($outage, QueueType::Infrastructure, $anyInfra)['region_coverage']['passed'], 'no region: everything');
        $this->assertFalse($this->checks($visit, QueueType::VisitDispatch, $northVisits)['region_coverage']['passed'], 'by the OLT, though the visit has no region');
    }
}
