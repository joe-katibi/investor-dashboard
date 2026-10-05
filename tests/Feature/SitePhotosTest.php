<?php

namespace Tests\Feature;

use App\Enums\StatusCode;
use App\Filament\Resources\Outages\OutageResource;
use App\Filament\Resources\Outages\Pages\ViewOutage;
use App\Models\MajorOutageType;
use App\Models\Outage;
use App\Models\OutagePhoto;
use App\Models\OutageType;
use App\Models\OutageUpdate;
use App\Models\User;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Emergency and Regular outages are reported restored with three site photos: the findings on the
 * ground, the work after restoration and the power readings. (In the mobile app they can only be
 * taken with the camera — MobileApiTest; on the website they are chosen from the computer.)
 */
class SitePhotosTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
    }

    /** @return array{0: Outage, 1: User, 2: User} an outage dispatched to a technician; the technician; the NOC engineer */
    private function dispatched(string $type = 'regular'): array
    {
        $team = $this->makeTeam('Field Ops '.$type, 'INFRA');
        $crew = $this->makeTeam('Crew '.$type, 'INFRA', $team);
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $tech = $this->makeUser('Outage Technician', 'INFRA', [$crew]);

        $outage = $this->reportOutage($this->makeUser('CX Agent', 'CX'), ['outage_type_id' => OutageType::query()->where('code', $type)->value('id')]);
        Auth::setUser($noc);
        $outage = $this->workflow->confirmByNoc($outage, $noc);
        Auth::setUser($dispatcher);
        $outage = $this->workflow->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $crew->id], $dispatcher);
        Auth::setUser($tech);

        return [$outage, $tech, $noc];
    }

    /** A picture already uploaded to the private disk, as the website's upload field leaves it. */
    private function uploaded(string $name): string
    {
        return UploadedFile::fake()->image($name.'.jpg', 640, 480)->store('outage-photos/test', 'local');
    }

    private function refused(callable $attempt, string $key): string
    {
        try {
            $attempt();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());

            return $e->errors()[$key][0];
        }

        $this->fail("Expected to be refused on [{$key}]");
    }

    public function test_emergency_and_regular_outages_are_reported_restored_with_three_site_photos(): void
    {
        foreach (['regular', 'emergency'] as $type) {
            [$outage, $tech] = $this->dispatched($type);
            $this->assertTrue($outage->requiresSitePhotos(), $type);
            $this->assertTrue($tech->can('addSitePhotos', $outage));
            $this->assertSame(['findings' => 'Findings on ground', 'restored' => 'After restoration', 'power' => 'Power readings'], $outage->missingSitePhotos());

            // No photos: not restored, and the message says which are missing.
            $message = $this->refused(fn () => $this->workflow->reportRestored($outage, $tech, 'Spliced'), 'photos');
            $this->assertStringContainsString('Findings on ground, After restoration, Power readings', $message);
            $this->assertTrue($outage->refresh()->status->isCode(StatusCode::TeamDispatched));

            // Added as the work goes: the findings first.
            $this->refused(fn () => $this->workflow->addSitePhotos($outage, $tech, []), 'photos');
            $this->refused(fn () => $this->workflow->addSitePhotos($outage, $tech, ['findings' => 'private/somewhere-else.jpg']), 'photos.findings');
            $this->refused(fn () => $this->workflow->addSitePhotos($outage, $tech, ['findings' => 'outage-photos/../.env']), 'photos.findings');

            $outage = $this->workflow->addSitePhotos($outage, $tech, ['findings' => [$this->uploaded('findings')], 'findings_name' => 'IMG_0001.jpg', 'source' => OutagePhoto::SOURCE_UPLOAD]);
            $findings = $outage->sitePhotos()['findings'];
            $this->assertSame([OutagePhoto::SITE, OutagePhoto::SOURCE_UPLOAD, 'IMG_0001.jpg', $tech->id], [$findings->stage, $findings->source, $findings->original_name, $findings->user_id]);
            $this->assertNull($findings->taken_at, 'only the camera knows when a picture was taken');
            $this->assertSame('Findings on ground', $findings->label());
            $this->assertStringStartsWith('Uploaded ', $findings->caption());
            $this->assertSame(['restored' => 'After restoration', 'power' => 'Power readings'], $outage->missingSitePhotos());
            $this->assertTrue(OutageUpdate::query()->where('outage_id', $outage->id)
                ->where('body', 'Site photo added — Findings on ground. Still to take: After restoration, Power readings.')->exists());

            // Taken again: the later one is the photo of the ticket.
            $this->workflow->addSitePhotos($outage, $tech, ['findings' => $this->uploaded('findings-2'), 'findings_taken_at' => now()->subMinutes(5), 'findings_source' => OutagePhoto::SOURCE_CAMERA]);
            $retaken = $outage->sitePhotos()['findings'];
            $this->assertNotSame($findings->id, $retaken->id);
            $this->assertSame(OutagePhoto::SOURCE_CAMERA, $retaken->source);
            $this->assertStringStartsWith('Taken ', $retaken->caption());

            // Two of three is still not enough; the last two can come with the restoration itself.
            $this->refused(fn () => $this->workflow->reportRestored($outage, $tech, 'Spliced', null, ['restored' => $this->uploaded('after')]), 'photos');
            $this->assertNull($outage->sitePhotos()['restored'], 'a refused restoration saves nothing');

            $outage = $this->workflow->reportRestored($outage, $tech, 'Spliced', null, ['restored' => $this->uploaded('after'), 'power' => $this->uploaded('power')]);
            $this->assertTrue($outage->status->isCode(StatusCode::RestoreReported));
            $this->assertSame([], $outage->missingSitePhotos());
            $this->assertFalse($tech->can('addSitePhotos', $outage), 'the photos are closed with the restoration');
        }
    }

    public function test_other_outages_and_restorations_without_a_field_team_need_no_site_photos(): void
    {
        foreach (['core', 'system', 'planned', 'preventive', 'corrective'] as $code) {
            $type = OutageType::query()->where('code', $code)->firstOrFail();
            $outage = (new Outage)->forceFill(['outage_type_id' => $type->id, 'major_outage_type_id' => $type->major_outage_type_id, 'maintenance_kind' => $type->maintenance_kind]);
            $this->assertFalse($outage->requiresSitePhotos(), $code);
        }

        // Which types need them is a setting of the type…
        [$outage, $tech] = $this->dispatched();
        OutageType::query()->where('code', 'regular')->firstOrFail()->update(['requires_site_photos' => false]);
        $this->assertFalse($outage->requiresSitePhotos());
        $this->assertFalse($tech->can('addSitePhotos', $outage));
        $this->assertTrue($this->workflow->reportRestored($outage, $tech, 'Spliced')->status->isCode(StatusCode::RestoreReported));

        // …and only for outages a field team goes out to.
        OutageType::query()->where('code', 'regular')->firstOrFail()->update(['requires_site_photos' => true]);
        $remote = $this->reportOutage($this->makeUser('CX Agent', 'CX'));
        $this->assertTrue($remote->requiresSitePhotos());
        MajorOutageType::query()->findOrFail($remote->major_outage_type_id)->update(['requires_infrastructure' => false]);
        $this->assertFalse($remote->requiresSitePhotos(), 'restored remotely by the NOC: nothing to photograph');
    }

    public function test_the_website_shows_the_photos_and_asks_for_the_missing_ones(): void
    {
        [$outage, $tech] = $this->dispatched();
        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
        $this->workflow->addSitePhotos($outage, $tech, ['findings' => $this->uploaded('findings')]);

        $this->actingAs($admin);
        $this->get(OutageResource::getUrl('view', ['record' => $outage]))->assertOk()
            ->assertSee('Site photos (1 of 3)')
            ->assertSee('Findings on ground')->assertSee('After restoration')->assertSee('Power readings')
            ->assertSee('Not taken yet');

        // Report restored: the two photos still missing are required, the one on the ticket is not.
        Livewire::test(ViewOutage::class, ['record' => $outage->getRouteKey()])
            ->assertActionVisible(TestAction::make('addSitePhotos'))
            ->callAction(TestAction::make('reportRestored'), ['note' => 'Spliced'])
            ->assertHasFormErrors(['site_restored' => 'required', 'site_power' => 'required'])
            ->assertHasNoFormErrors(['site_findings']);
        $this->assertTrue($outage->refresh()->status->isCode(StatusCode::TeamDispatched));

        // Chosen from the computer.
        Livewire::test(ViewOutage::class, ['record' => $outage->getRouteKey()])
            ->callAction(TestAction::make('reportRestored'), [
                'note' => 'Spliced, levels normal',
                'site_restored' => [UploadedFile::fake()->image('after.jpg', 640, 480)],
                'site_power' => [UploadedFile::fake()->image('power.jpg', 640, 480)],
            ])
            ->assertHasNoFormErrors();

        $outage->refresh();
        $this->assertTrue($outage->status->isCode(StatusCode::RestoreReported));
        $this->assertSame([], $outage->missingSitePhotos());
        $power = $outage->sitePhotos()['power'];
        $this->assertSame(OutagePhoto::SOURCE_UPLOAD, $power->source);
        $this->assertSame('power.jpg', $power->original_name);
        Storage::disk('local')->assertExists($power->path);

        // The photos stay on the outage page after the restoration, each opened through its private address.
        $this->get(OutageResource::getUrl('view', ['record' => $outage]))->assertOk()->assertDontSee('Not taken yet');
        $this->get($power->url())->assertOk();
    }
}
