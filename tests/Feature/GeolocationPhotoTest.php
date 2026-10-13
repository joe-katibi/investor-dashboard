<?php

namespace Tests\Feature;

use App\Filament\Pages\TicketSettings;
use App\Filament\Resources\Tickets\RelationManagers\TicketAttachmentsRelationManager;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\OutageAttachment;
use App\Models\TicketSubcategory;
use App\Services\Geo\PhotoLocation;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * A geolocation photo keeps where and when it was taken: the position the mobile app sends with
 * it, or — for a photo that arrives without one — the position written in the photo file itself.
 */
class GeolocationPhotoTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A JPEG that carries a position in its EXIF data, as a phone camera (or the OMS app) writes it:
     * 0° 20' 17.7936" N, 32° 34' 53.0472" E = 0.338276, 32.581402, taken 13 Oct 2026 09:21:05.
     */
    private function taggedJpeg(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();

        $rational = fn (int $numerator, int $denominator) => pack('VV', $numerator, $denominator);
        $entry = fn (int $tag, int $type, int $count, string $value) => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");

        // TIFF (little endian): IFD0 → Exif IFD (the moment) and GPS IFD (the position).
        $ifd0 = pack('v', 2).$entry(0x8769, 4, 1, pack('V', 38)).$entry(0x8825, 4, 1, pack('V', 76)).pack('V', 0);          // 8 … 38
        $exif = pack('v', 1).$entry(0x9003, 2, 20, pack('V', 56)).pack('V', 0).'2026:10:13 09:21:05'."\0";                  // 38 … 76
        $gps = pack('v', 4)
            .$entry(1, 2, 2, "N\0").$entry(2, 5, 3, pack('V', 130))
            .$entry(3, 2, 2, "E\0").$entry(4, 5, 3, pack('V', 154)).pack('V', 0);                                           // 76 … 130
        $data = $rational(0, 1).$rational(20, 1).$rational(177936, 10000).$rational(32, 1).$rational(34, 1).$rational(530472, 10000);
        $tiff = "II*\0".pack('V', 8).$ifd0.$exif.$gps.$data;
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    public function test_a_photo_that_carries_its_position_is_read_for_it(): void
    {
        Storage::disk('local')->put($path = OutageAttachment::DIRECTORY.'/2026/10/tagged.jpg', $this->taggedJpeg());
        Storage::disk('local')->put($plain = OutageAttachment::DIRECTORY.'/2026/10/plain.jpg', 'not a picture with a position');

        $read = app(PhotoLocation::class)->for($path);
        $this->assertEqualsWithDelta(0.338276, $read['latitude'], 0.0000005);
        $this->assertEqualsWithDelta(32.581402, $read['longitude'], 0.0000005);
        $this->assertSame(['2026-10-13 09:21:05', 'photo', null], [$read['taken_at'], $read['location_source'], $read['accuracy_m']]);

        // What the app sent wins over what the file says; nonsense and "no fix" (0, 0) are not a position.
        $given = app(PhotoLocation::class)->for($path, ['lat' => '0.3136', 'lng' => '32.5811', 'accuracy' => 12.4, 'place' => ' Kololo, Kampala ', 'taken_at' => '2026-10-13T06:59:30Z']);
        $this->assertSame([0.3136, 32.5811, 12, 'Kololo, Kampala', '2026-10-13 09:59:30', 'device'], array_values($given));
        $this->assertSame('photo', app(PhotoLocation::class)->for($path, ['lat' => 0, 'lng' => 0])['location_source']);
        $this->assertSame('photo', app(PhotoLocation::class)->for($path, ['lat' => 'abc', 'lng' => 200])['location_source']);
        $this->assertSame([], app(PhotoLocation::class)->for($plain));
        $this->assertSame([], app(PhotoLocation::class)->for('outage-attachments/nothing-here.jpg'));
    }

    public function test_a_geolocation_photo_added_on_the_website_shows_where_it_was_taken(): void
    {
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        $workflow = app(OutageWorkflow::class);
        Auth::setUser($frontline);
        $ticket = $workflow->createTicket([
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => 'SF123456', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001', 'address' => 'Seguku',
        ], $frontline);

        Storage::disk('local')->put($tagged = OutageAttachment::DIRECTORY.'/2026/10/geo.jpg', $this->taggedJpeg());
        Storage::disk('local')->put($other = OutageAttachment::DIRECTORY.'/2026/10/speed.jpg', $this->taggedJpeg());
        Auth::setUser($tier2);
        $workflow->addTicketAttachments($ticket, $tier2, [$tagged], [$tagged => 'geo.jpg'], null, 'geo_photo');
        $workflow->addTicketAttachments($ticket, $tier2, [$other], [$other => 'speed.jpg'], null, 'speed_test');

        // Only the geolocation photo is read for a position.
        $geo = OutageAttachment::query()->where('kind', 'geo_photo')->sole();
        $this->assertSame(['0.338276, 32.581402', 'photo', '2026-10-13 09:21:05'], [$geo->coordinates(), $geo->location_source, $geo->taken_at->toDateTimeString()]);
        $this->assertFalse(OutageAttachment::query()->where('kind', 'speed_test')->sole()->hasLocation());

        $this->actingAs($tier2);
        Livewire::test(TicketAttachmentsRelationManager::class, ['ownerRecord' => $ticket->refresh(), 'pageClass' => ViewTicket::class])
            ->assertSee('0.338276, 32.581402')
            ->assertSee('13 Oct 2026 09:21:05')
            ->assertSeeHtml('https://www.google.com/maps?q=0.3382760,32.5814020');
    }

    public function test_the_two_switches_are_under_ticket_settings(): void
    {
        $this->actingAs($this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']));

        Livewire::test(TicketSettings::class)
            ->assertSee('The geolocation photo must carry the technician’s position')
            ->assertSee('Add the place name and a small map to the geolocation photo')
            ->assertFormSet(['require_location' => true, 'geo_lookup' => true])
            ->fillForm(['require_location' => false, 'geo_lookup' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(app(Settings::class)->bool('ticketing.require_location'));
        $this->assertFalse(app(Settings::class)->bool('geo.lookup'));
    }
}
