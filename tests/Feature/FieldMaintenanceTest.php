<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Filament\Pages\RaiseFieldMaintenance;
use App\Filament\Resources\Outages\OutageResource;
use App\Mail\OutageNotificationMail;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\OutagePhoto;
use App\Models\OutageType;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** Preventive (approved downtime) and corrective maintenance raised on site, with before / after photos. */
class FieldMaintenanceTest extends TestCase
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

    /** @return array{outside: string, inside: string} */
    private function photos(string $stage = 'before'): array
    {
        $paths = [];

        foreach (['outside', 'inside'] as $position) {
            $paths[$position] = UploadedFile::fake()->image("{$stage}-{$position}.jpg", 640, 480)->store('outage-photos/test', 'local');
        }

        return $paths;
    }

    /** @return array{0: User, 1: User, 2: User, 3: User} technician, dispatcher, infrastructure manager, NOC manager (stakeholder) */
    private function people(): array
    {
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $crew = $this->makeTeam('Crew', 'INFRA', $team);

        return [
            $this->makeUser('Outage Technician', 'INFRA', [$crew]),
            $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team], ['availability_status' => 'offline']),
            $this->makeUser('Infrastructure Manager', 'INFRA', [$team], ['availability_status' => 'offline']),
            $this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline', 'email' => 'noc.manager@example.com']),
        ];
    }

    private function raise(User $tech, string $kind, array $overrides = []): Outage
    {
        Auth::setUser($tech);

        return $this->workflow->raiseFieldMaintenance(array_merge([
            'kind' => $kind,
            'title' => $kind === OutageType::PREVENTIVE ? 'Replace water-damaged splitter' : 'Pole down — drop cables cut',
            'description' => 'Found on site during inspection.',
            'site_label' => 'FAT-KBW-017',
            'location' => 'Kabowa',
            'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'),
            'customers_affected' => 12,
            'downtime_start' => now()->addDay()->setTime(1, 0),
            'downtime_end' => now()->addDay()->setTime(3, 0),
            'photos' => $this->photos(),
        ], $overrides), $tech);
    }

    public function test_preventive_needs_dispatcher_and_manager_approval_then_everyone_is_notified(): void
    {
        [$tech, $dispatcher, $manager, $nocManager] = $this->people();

        $outage = $this->raise($tech, OutageType::PREVENTIVE);
        $this->assertTrue($outage->status->isCode(StatusCode::PmPendingApproval));
        $this->assertSame('PVM', explode('-', $outage->ticket_number)[0]);
        $this->assertSame(2, $outage->photos()->where('stage', OutagePhoto::BEFORE)->count());
        $this->assertTrue(NotificationLog::query()->where('recipient_user_id', $dispatcher->id)->where('event', 'preventive_requested')->where('channel', 'database')->exists());
        $this->assertTrue(NotificationLog::query()->where('recipient_user_id', $manager->id)->where('event', 'preventive_requested')->exists());
        $this->assertFalse($tech->can('approvePreventive', $outage), 'the technician cannot approve their own request');
        $this->assertFalse($dispatcher->can('dispatch', $outage), 'nothing is dispatched before approval');

        // Dispatcher approves → still waiting for the Infrastructure Manager.
        Auth::setUser($dispatcher);
        $outage = $this->workflow->approvePreventive($outage, $dispatcher, 'OK for 01:00');
        $this->assertTrue($outage->status->isCode(StatusCode::PmPendingApproval));
        $this->assertFalse($dispatcher->can('approvePreventive', $outage), 'one approval per person');

        // Infrastructure Manager approves → approved, dispatched to the technician, everyone told.
        Auth::setUser($manager);
        $outage = $this->workflow->approvePreventive($outage, $manager);
        $this->assertTrue($outage->status->isCode(StatusCode::TeamDispatched));
        $this->assertNotNull($outage->pm_approved_at);
        $this->assertSame($tech->id, $outage->current_assignee_id);
        $this->assertSame(QueueType::Technician, $outage->current_queue);

        $mail = NotificationLog::query()->where('event', 'preventive_approved')->where('channel', 'mail')->where('recipient', 'noc.manager@example.com')->first();
        $this->assertNotNull($mail, 'stakeholders (NOC, CX, …) get the downtime approval e-mail');
        $this->assertCount(2, $mail->meta['photo_ids']);
        $this->assertTrue(NotificationLog::query()->where('event', 'preventive_approved')->where('channel', 'database')->where('recipient_user_id', $tech->id)->exists());
        Mail::assertSent(OutageNotificationMail::class, fn (OutageNotificationMail $m) => $m->hasTo('noc.manager@example.com') && count($m->attachments()) === 2);

        // Completion needs the two "after" photos.
        Auth::setUser($tech);

        try {
            $this->workflow->reportRestored($outage, $tech, 'Splitter replaced');
            $this->fail('After photos are required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photos', $e->errors());
        }

        $outage = $this->workflow->reportRestored($outage, $tech, 'Splitter replaced', null, $this->photos('after'));
        $this->assertTrue($outage->status->isCode(StatusCode::RestoreReported));
        $this->assertSame(4, $outage->photos()->count());
    }

    public function test_rejected_preventive_maintenance_closes_without_sla_and_tells_the_technician(): void
    {
        [$tech, , $manager] = $this->people();
        $outage = $this->raise($tech, OutageType::PREVENTIVE);

        Auth::setUser($manager);
        $outage = $this->workflow->rejectPreventive($outage, $manager, 'Do it with the Q4 upgrade');

        $this->assertTrue($outage->status->isCode(StatusCode::PmRejected));
        $this->assertTrue($outage->is_closed);
        $this->assertSame(SlaState::NotApplicable, $outage->sla_state);
        $this->assertTrue(NotificationLog::query()->where('recipient_user_id', $tech->id)->where('event', 'preventive_rejected')->exists());
    }

    public function test_corrective_starts_at_once_with_a_support_technician(): void
    {
        [$tech] = $this->people();
        $support = $this->makeUser('Support/Installation Technician', 'DISPATCH');

        $outage = $this->raise($tech, OutageType::CORRECTIVE, [
            'support' => ['work_required' => 'Re-run 6 dropped cables to premises', 'customer_details' => 'Kabowa estate'],
        ]);

        $this->assertTrue($outage->status->isCode(StatusCode::TeamDispatched));
        $this->assertSame($tech->id, $outage->current_assignee_id, 'the technician who raised it owns it');
        $this->assertNotNull($outage->dispatched_at);
        $this->assertSame(1, $outage->supportRequests()->open()->count());
        $this->assertTrue($outage->activeAssignments()->where('user_id', $support->id)->exists(), 'support technician assigned in parallel');
        $this->assertTrue(NotificationLog::query()->where('event', 'corrective_raised')->where('recipient', 'noc.manager@example.com')->exists());

        // Support done, then the technician closes with after photos.
        Auth::setUser($support);
        $this->workflow->completeSupport($outage, $support, 'Drop cables re-run');
        Auth::setUser($tech);
        $outage = $this->workflow->reportRestored($outage->refresh(), $tech, 'New pole, cables re-spliced', null, $this->photos('after'));
        $this->assertTrue($outage->status->isCode(StatusCode::RestoreReported));
        $this->assertSame(SupportRequest::COMPLETED, $outage->supportRequests()->first()->status);
    }

    public function test_photos_are_required_and_private(): void
    {
        [$tech] = $this->people();
        $agent = $this->makeUser('CX Agent', 'CX');

        try {
            $this->raise($tech, OutageType::CORRECTIVE, ['photos' => ['outside' => '../../.env', 'inside' => null]]);
            $this->fail('Valid before photos are required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photos.outside', $e->errors());
        }

        try {
            $this->raise($agent, OutageType::CORRECTIVE);
            $this->fail('Only Infrastructure raises field maintenance');
        } catch (AuthorizationException) {
        }

        $photo = $this->raise($tech, OutageType::CORRECTIVE)->photos()->first();
        $this->app['auth']->forgetGuards();
        $this->get($photo->url())->assertRedirect();
        $this->actingAs($agent)->get($photo->url())->assertOk();
    }

    public function test_screens(): void
    {
        [$tech, $dispatcher] = $this->people();
        $agent = $this->makeUser('CX Agent', 'CX');

        $this->actingAs($agent)->get(RaiseFieldMaintenance::getUrl())->assertForbidden();
        $this->actingAs($tech)->get(RaiseFieldMaintenance::getUrl())->assertOk()->assertSee('Photos before the work');

        $outage = $this->raise($tech, OutageType::PREVENTIVE);
        $this->actingAs($dispatcher)->get(OutageResource::getUrl('view', ['record' => $outage]))->assertOk()
            ->assertSee('Approve downtime (Dispatcher)')->assertSee('FAT-KBW-017')->assertSee($outage->photos()->first()->url(), false);
    }
}
