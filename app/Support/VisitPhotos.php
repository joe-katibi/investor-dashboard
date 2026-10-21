<?php

namespace App\Support;

use App\Models\Outage;
use App\Models\OutageAttachment;
use Illuminate\Support\Collection;

/**
 * The photos a site visit is completed with. Which ones depends on the kind of visit
 * (Customer Care → Visit Types → "Photos to complete"):
 *
 *   standard      the geolocation photo and the speed-test photo;
 *   installation  the nine photos of an installation or a relocation — the FAT outside (geotagged),
 *                 inside and its power, the cable entry (geotagged), the ATB and ONU mounted, the
 *                 power at the ATB, the ONU's serial number, the speed tests and the customer's
 *                 devices working. Taken in the mobile app with the camera only: there is no
 *                 upload for them on the website.
 *
 * A geotagged photo is taken at the phone's position (stamped on it, saved with it, read back
 * from the file) and is listed in the Geolocation Photos report.
 */
final class VisitPhotos
{
    public const STANDARD = 'standard';

    public const INSTALLATION = 'installation';

    /** The technician's answer on a visit: the customer's ONU stayed, or a new one went in. */
    public const ONU_EXISTING = 'existing';

    public const ONU_REPLACED = 'replaced';

    public const ONU_STATUSES = [self::ONU_EXISTING => 'Existing ONU', self::ONU_REPLACED => 'ONU replaced'];

    public const SETS = [
        self::STANDARD => 'Geolocation photo and speed-test photo',
        self::INSTALLATION => 'Installation set: nine photos, two geotagged — mobile app only',
    ];

    /** kind (as stored on the attachment) => what the technician is asked for, a short name, geotagged?, several photos? */
    public const PHOTOS = [
        'geo_photo' => ['label' => 'Geolocation photo', 'short' => 'Geolocation photo', 'geotag' => true, 'many' => false,
            'help' => 'Taken at the customer premise: your position, the place and the time are stamped on the photo.'],
        'fat_outside' => ['label' => 'Geotagged photograph of the outside of the FAT serving this connection', 'short' => 'FAT — outside (geotagged)', 'geotag' => true, 'many' => false,
            'help' => 'Taken at the FAT: your position, the place and the time are stamped on the photo.'],
        'fat_inside' => ['label' => 'Photograph inside the FAT after splicing, showing the terminations', 'short' => 'FAT — inside, terminations', 'geotag' => false, 'many' => false,
            'help' => 'After splicing, with the terminations visible.'],
        'fat_power' => ['label' => 'Power levels at the FAT: output power of this port', 'short' => 'FAT — port power', 'geotag' => false, 'many' => false,
            'help' => 'The power meter reading at the port of this connection.'],
        'cable_entry' => ['label' => 'Geotagged photograph of the cable entry towards the premises', 'short' => 'Cable entry (geotagged)', 'geotag' => true, 'many' => false,
            'help' => 'Where the drop cable enters the premises: your position, the place and the time are stamped on the photo.'],
        'atb_onu' => ['label' => 'Installation photograph showing the ATB and ONU mounted in the house', 'short' => 'ATB and ONU mounted', 'geotag' => false, 'many' => false,
            'help' => 'Both mounted, in one picture.'],
        'atb_power' => ['label' => 'Power readings at the ATB', 'short' => 'ATB — power', 'geotag' => false, 'many' => false,
            'help' => 'The power meter reading at the ATB.'],
        'onu_serial' => ['label' => 'Photograph of the ONU showing the serial number', 'short' => 'ONU serial number', 'geotag' => false, 'many' => false,
            'help' => 'The label with the serial number, readable.'],
        'escalation_geo' => ['label' => 'Geotagged photo of the site', 'short' => 'Escalation to Infrastructure Support (geotagged)', 'geotag' => true, 'many' => false,
            'help' => 'Taken on site, showing what Infrastructure Support has to fix: your position, the place and the time are stamped on the photo.'],
        'speed_test' => ['label' => 'Speed test photo', 'short' => 'Speed test', 'geotag' => false, 'many' => false,
            'help' => 'Photo of the speed test result after the fix.'],
        'onu_mac' => ['label' => 'Photo of the MAC address at the back of the ONU', 'short' => 'ONU MAC address', 'geotag' => false, 'many' => false,
            'help' => 'The label at the back of the new ONU, with the MAC address readable: the NOC provisions it from this.'],
        'devices_ok' => ['label' => 'Confirmation that every smart TV and streaming device is working', 'short' => 'Devices working', 'geotag' => false, 'many' => false,
            'help' => 'The customer’s smart TV / streaming devices playing.'],
    ];

    /** In an installation the speed test is two results: wired at the ONU, then Wi-Fi in the room of use. */
    private const INSTALLATION_SPEED = ['label' => 'Speed tests: wired at the ONU, then Wi-Fi in the room of use', 'short' => 'Speed tests (wired, Wi-Fi)', 'geotag' => false, 'many' => true,
        'help' => 'Photograph both results: one wired at the ONU, one on Wi-Fi in the room the customer uses.'];

    private const KINDS = [
        self::STANDARD => ['geo_photo', 'speed_test'],
        self::INSTALLATION => ['fat_outside', 'fat_inside', 'fat_power', 'cable_entry', 'atb_onu', 'atb_power', 'onu_serial', 'speed_test', 'devices_ok'],
    ];

    /** Short names of every kind, for the "Type" of a file in a list. */
    public const SHORT = [
        'geo_photo' => 'Geolocation photo', 'speed_test' => 'Speed test photo', 'fat_outside' => 'FAT — outside (geotagged)',
        'fat_inside' => 'FAT — inside, terminations', 'fat_power' => 'FAT — port power', 'cable_entry' => 'Cable entry (geotagged)',
        'atb_onu' => 'ATB and ONU mounted', 'atb_power' => 'ATB — power', 'onu_serial' => 'ONU serial number', 'onu_mac' => 'ONU MAC address', 'devices_ok' => 'Devices working',
        'escalation_geo' => 'Escalation to Infrastructure Support (geotagged)',
    ];

    /** Relocations: these are taken once the NOC has provisioned the service at the new location. */
    public const AFTER_PROVISIONING = ['speed_test', 'devices_ok'];

    /** A Shifting / relocation visit (Visit Types → "Relocation"). */
    public static function isRelocation(?Outage $visit): bool
    {
        return (bool) ($visit?->isSiteVisit() && $visit->ticket?->visitType?->is_relocation);
    }

    /**
     * A new ONU went in on this visit: the technician said so ("ONU replaced — send to NOC for provisioning"). An ONU
     * issue no longer means it on its own — a Faulty ONU is sometimes fixed with the customer's own ONU.
     */
    public static function onuChange(?Outage $visit): bool
    {
        return (bool) ($visit?->isSiteVisit() && ! self::isRelocation($visit) && $visit->ticket?->onu_status === self::ONU_REPLACED);
    }

    /**
     * The technician is asked on completing whether the customer kept their ONU or got a new one: ONU issues (Faulty
     * ONU, ONU Upgrade, ONU Delivery, ONU Swap — Subcategories → "Ask about the ONU"). Optional; other visits do not ask.
     */
    public static function asksOnu(?Outage $visit): bool
    {
        return (bool) ($visit?->isSiteVisit() && ! self::isRelocation($visit) && $visit->ticket?->subcategory?->needs_onu_provisioning);
    }

    /** The FTTH NOC provisions the service before the speed tests: relocations and new ONUs. */
    public static function needsProvisioning(?Outage $visit): bool
    {
        return self::isRelocation($visit) || self::onuChange($visit);
    }

    /**
     * The photos that cannot be taken yet: on a relocation not provisioned by the NOC, the speed tests
     * and the smart-device photo.
     *
     * @return list<string> kinds
     */
    public static function locked(?Outage $visit): array
    {
        if (! self::needsProvisioning($visit) || $visit->ticket?->provisioned_at !== null) {
            return [];
        }

        return array_values(array_intersect(self::AFTER_PROVISIONING, array_keys(self::of($visit))));
    }

    /** Which set this visit is completed with. */
    public static function setOf(?Outage $visit): string
    {
        $set = $visit?->isSiteVisit() ? $visit->ticket?->visitType?->photo_set : null;

        return isset(self::KINDS[$set]) ? $set : self::STANDARD;
    }

    /** The installation set is taken in the mobile app only: nothing is uploaded for it on the website. */
    public static function appOnly(?Outage $visit): bool
    {
        return self::setOf($visit) === self::INSTALLATION;
    }

    /**
     * The photos of this visit's set, in the order they are taken.
     *
     * @return array<string, array{label: string, short: string, geotag: bool, many: bool, help: string, field: string}> by kind
     */
    public static function of(?Outage $visit): array
    {
        $set = self::setOf($visit);
        $photos = [];

        // A new ONU: its MAC address (for the NOC) and the smart devices working, around the speed test.
        $kinds = self::onuChange($visit)
            ? array_values(array_unique([...array_diff(self::KINDS[$set], ['speed_test', 'devices_ok']), 'onu_mac', 'speed_test', 'devices_ok']))
            : self::KINDS[$set];

        foreach ($kinds as $kind) {
            $photos[$kind] = ($set === self::INSTALLATION && $kind === 'speed_test' ? self::INSTALLATION_SPEED : self::PHOTOS[$kind]) + ['field' => self::field($kind)];
        }

        return $photos;
    }

    /** The name of the photo in a form (the speed test has always been "speed_photo"). */
    public static function field(string $kind): string
    {
        return $kind === 'speed_test' ? 'speed_photo' : $kind;
    }

    public static function geotagged(?string $kind): bool
    {
        return (bool) (self::PHOTOS[$kind]['geotag'] ?? false);
    }

    /** @return list<string> the kinds listed in the Geolocation Photos report */
    public static function geotaggedKinds(): array
    {
        return array_keys(array_filter(self::PHOTOS, fn (array $photo) => $photo['geotag']));
    }

    public static function short(?string $kind): ?string
    {
        return self::SHORT[$kind] ?? null;
    }

    /**
     * The photo of each kind on the visit now (the latest one), null where it has not been taken.
     *
     * @return Collection<string, ?OutageAttachment>
     */
    public static function taken(Outage $visit): Collection
    {
        $kinds = array_keys(self::of($visit));
        $files = OutageAttachment::query()->where('outage_id', $visit->id)->whereIn('kind', $kinds)->with('user:id,name')->orderBy('id')->get()->groupBy('kind');

        return collect($kinds)->mapWithKeys(fn (string $kind) => [$kind => $files->get($kind)?->last()]);
    }

    /** @return array<string, string> kind => label, of the photos still to take */
    public static function missing(Outage $visit): array
    {
        $taken = self::taken($visit);

        return collect(self::of($visit))->reject(fn (array $photo, string $kind) => $taken[$kind] !== null)->map(fn (array $photo) => $photo['label'])->all();
    }

    /** "7 of 9" */
    public static function progress(Outage $visit): string
    {
        $all = count(self::of($visit));

        return ($all - count(self::missing($visit)))." of {$all}";
    }
}
