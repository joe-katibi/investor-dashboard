<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** Team OLT coverage only limits technicians when "Restrict technicians to their teams' OLT coverage" is on. */
class OltCoverageTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    public function test_any_technician_can_take_any_olt_unless_coverage_is_switched_on(): void
    {
        $this->seedReference();
        Mail::fake();

        $salaama = $this->makeOlt('Salaama');
        $njewa = $this->makeOlt('Njewa');
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $north = $this->makeTeam('North Crew', 'INFRA', $team);
        $north->olts()->sync([$salaama->id]); // North Crew "covers" Salaama only

        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $tina = $this->makeUser('Outage Technician', 'INFRA', [$north]);
        $workflow = app(OutageWorkflow::class);

        $dispatchTo = function (Olt $olt) use ($workflow, $agent, $noc, $dispatcher, $team, $north, $tina) {
            $outage = $this->reportOutage($agent, ['olt_ids' => [$olt->id]]);
            Auth::setUser($noc);
            $outage = $workflow->confirmByNoc($outage, $noc);
            Auth::setUser($dispatcher);

            return $workflow->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $north->id, 'technician_id' => $tina->id], $dispatcher);
        };

        // Default: coverage does not restrict — Tina takes an outage on Njewa.
        $this->assertSame($tina->id, $dispatchTo($njewa)->technician_id);

        // Switched on: only teams covering the OLT are eligible.
        app(Settings::class)->set(['fifo.olt_coverage' => true]);
        $this->expectException(ValidationException::class);
        $dispatchTo($njewa);
    }
}
