<?php

namespace Tests\Feature;

use App\Filament\Pages\MyWork;
use App\Filament\Resources\Outages\Pages\ViewOutage;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\TicketAttachmentsRelationManager;
use App\Filament\Support\PhotoViewer;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\OutagePhoto;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Services\Outages\OutageWorkflow;
use Filament\Support\Facades\FilamentView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * A photo is looked at in a pop-up in the middle of the page, the same way everywhere on the
 * website — never in another tab — and "Download" hands the file to the browser.
 */
class PhotoViewerTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
    }

    /** A customer ticket with a photo and a PDF on it, and the CX Tier II person who added them. */
    private function ticketWithFiles(): array
    {
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        $workflow = app(OutageWorkflow::class);

        Auth::setUser($frontline);
        $ticket = $workflow->createTicket([
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => 'SF123456', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001', 'address' => 'Seguku',
        ], $frontline);

        $photo = UploadedFile::fake()->image('speed.jpg', 640, 480)->store(OutageAttachment::DIRECTORY.'/2026/10', 'local');
        Storage::disk('local')->put($pdf = OutageAttachment::DIRECTORY.'/2026/10/receipt.pdf', '%PDF-1.4 receipt');

        Auth::setUser($tier2);
        $workflow->addTicketAttachments($ticket, $tier2, [$photo], [$photo => 'speed test.jpg'], 'After the fix <b>fast</b>', 'speed_test');
        $workflow->addTicketAttachments($ticket, $tier2, [$pdf], [$pdf => 'receipt.pdf'], 'Customer receipt');

        return [$ticket->refresh(), $tier2, OutageAttachment::query()->where('kind', 'speed_test')->sole(), OutageAttachment::query()->whereNull('kind')->sole()];
    }

    public function test_the_pop_up_is_on_every_signed_in_page_and_nowhere_else(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('data-oms-photo-viewer', false);

        $this->actingAs($this->makeUser('NOC Tier II', 'NOC'));
        $this->get(MyWork::getUrl())->assertOk()
            ->assertSee('data-oms-photo-viewer', false)
            ->assertSee('x-on:oms-photo.window', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('Download')
            ->assertSee('x-on:keydown.escape.window', false);
    }

    public function test_photos_and_files_of_a_ticket_have_view_and_download(): void
    {
        [$ticket, $tier2, $photo, $pdf] = $this->ticketWithFiles();
        $this->actingAs($tier2);

        Livewire::test(TicketAttachmentsRelationManager::class, ['ownerRecord' => $ticket, 'pageClass' => ViewTicket::class])
            // A photo: View (opens the pop-up on this page) and Download. Another kind of file: Download only.
            ->assertTableActionVisible('viewPhoto', $photo)
            ->assertTableActionHidden('viewPhoto', $pdf)
            ->assertTableActionVisible('download', $photo)
            ->assertTableActionVisible('download', $pdf)
            ->assertTableActionHasUrl('download', $photo->url(), $photo)
            ->assertTableActionHasUrl('download', $pdf->url(), $pdf)
            ->assertSeeHtml('oms-photo')
            ->assertDontSeeHtml('target="_blank"');

        // What the click hands to the pop-up: the photo, what it is, and where to download it — as data.
        $click = PhotoViewer::forAttachment($photo->load('user'));
        $this->assertStringStartsWith("\$dispatch('oms-photo', JSON.parse('", $click);
        $data = json_decode(json_decode('"'.substr($click, strlen("\$dispatch('oms-photo', JSON.parse('"), -3).'"'), true);
        $this->assertSame([$photo->url(), 'Speed test photo', $photo->url()], [$data['src'], $data['title'], $data['download']]);
        $this->assertSame('speed test.jpg · After the fix <b>fast</b> · added by '.$tier2->name, $data['caption']);
        // Nothing a file name or a note contains can end the string or start a tag.
        $this->assertStringNotContainsString('<b>', $click);
        $this->assertStringNotContainsString('"', $click);
        $this->assertNull(PhotoViewer::forAttachment($pdf), 'a PDF is not shown as a picture');
        $this->assertNull(PhotoViewer::forAttachment(null));
    }

    public function test_a_file_link_is_never_opened_as_a_page_of_the_panel(): void
    {
        [, $tier2, $photo] = $this->ticketWithFiles();
        $this->actingAs($tier2);

        // The panel moves between its own pages without reloading; a file is not one of them.
        $this->get(MyWork::getUrl())->assertOk();
        foreach ([$photo->url(), url('/photos/5'), url('/photos/5?download=1'), url('/crf/9/pdf'), route('report-exports.download', 3)] as $file) {
            $this->assertFalse(FilamentView::hasSpaMode($file), $file);
        }
        $this->assertTrue(FilamentView::hasSpaMode(MyWork::getUrl()));

        // Download: the file itself, saved by the browser — for someone signed in who may see the ticket.
        $this->get($photo->url())->assertOk()->assertDownload('speed test.jpg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('image/', $this->get($photo->url())->headers->get('Content-Type'), 'so the pop-up can show it');

        Auth::logout();
        $this->get($photo->url())->assertRedirect();
    }

    public function test_site_photos_of_an_outage_open_in_the_pop_up_and_can_be_downloaded(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $outage = $this->reportOutage($agent);

        foreach (array_keys(OutagePhoto::SITE_PHOTOS) as $position) {
            OutagePhoto::query()->create([
                'outage_id' => $outage->id, 'stage' => OutagePhoto::SITE, 'position' => $position,
                'path' => UploadedFile::fake()->image("{$position}.jpg", 320, 240)->store('outage-photos/test', 'local'),
                'original_name' => "{$position}.jpg", 'taken_at' => now(), 'source' => OutagePhoto::SOURCE_CAMERA, 'user_id' => $noc->id,
            ]);
        }

        $photo = OutagePhoto::query()->where('outage_id', $outage->id)->orderBy('id')->firstOrFail();
        $this->actingAs($noc);

        // On the outage page: a click on a photo opens it large, here.
        Livewire::test(ViewOutage::class, ['record' => $outage->getRouteKey()])
            ->assertSeeHtml('oms-photo')
            ->assertSeeHtml('cursor: zoom-in')
            ->assertSeeHtml(e($photo->url()))
            ->assertDontSeeHtml('href="'.e($photo->url()).'" target="_blank"');

        $attributes = PhotoViewer::imageAttributes($photo->load('user'));
        $this->assertStringContainsString('oms-photo', $attributes['x-on:click']);
        $this->assertStringContainsString('download=1', html_entity_decode($attributes['x-on:click']));
        $this->assertSame([], PhotoViewer::imageAttributes(null));

        // Shown in the page as a picture; downloaded as a file.
        $shown = $this->get($photo->url())->assertOk();
        $this->assertStringStartsWith('image/', $shown->headers->get('Content-Type'));
        $this->assertStringNotContainsString('attachment', (string) $shown->headers->get('Content-Disposition'));
        $this->get($photo->url().'?download=1')->assertOk()->assertDownload($photo->original_name);

        Auth::logout();
        $this->get($photo->url().'?download=1')->assertRedirect();
    }
}
