<?php

namespace App\Services\Geo;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where and when a photo was taken, as the columns of an attachment.
 *
 * The mobile app sends the phone's position with the geolocation photo and also writes it into the
 * photo file (its EXIF data). A photo that arrives without the figures — uploaded on the website,
 * or taken with another camera app that tags its pictures — is read for them here.
 */
class PhotoLocation
{
    public const FROM_DEVICE = 'device';

    public const FROM_PHOTO = 'photo';

    /**
     * @param  string  $path  the stored photo, on the private disk
     * @param  array{lat?: mixed, lng?: mixed, accuracy?: mixed, place?: mixed, taken_at?: mixed}|null  $given  what the app sent with it
     * @return array<string, mixed> attachment columns; empty when the photo carries no position
     */
    public function for(string $path, ?array $given = null): array
    {
        return $this->given($given ?? []) ?? $this->read($path) ?? [];
    }

    /** The position sent by the app, when it is a real one. */
    private function given(array $given): ?array
    {
        if (! $this->valid($given['lat'] ?? null, $given['lng'] ?? null)) {
            return null;
        }

        return [
            'latitude' => round((float) $given['lat'], 7),
            'longitude' => round((float) $given['lng'], 7),
            'accuracy_m' => is_numeric($given['accuracy'] ?? null) ? max(0, (int) round((float) $given['accuracy'])) : null,
            'place' => filled($given['place'] ?? null) ? mb_substr(trim((string) $given['place']), 0, 255) : null,
            'taken_at' => $this->moment($given['taken_at'] ?? null),
            'location_source' => self::FROM_DEVICE,
        ];
    }

    /** The position a photo file carries in its own data (EXIF), when it has one. */
    public function read(string $path): ?array
    {
        if (! function_exists('exif_read_data') || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        try {
            $exif = @exif_read_data(Storage::disk('local')->path($path), null, true);
        } catch (Throwable) {
            return null;
        }

        $gps = is_array($exif) ? ($exif['GPS'] ?? []) : [];
        $latitude = $this->degrees($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? 'N');
        $longitude = $this->degrees($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? 'E');

        if (! $this->valid($latitude, $longitude)) {
            return null;
        }

        // PHP does not know this tag by name in every version.
        $accuracy = $this->rational($gps['GPSHPositioningError'] ?? $gps['UndefinedTag:0x001F'] ?? null);
        $place = $exif['IFD0']['ImageDescription'] ?? null;

        return [
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'accuracy_m' => $accuracy !== null ? max(0, (int) round($accuracy)) : null,
            'place' => is_string($place) && trim($place) !== '' ? mb_substr(trim($place), 0, 255) : null,
            // The camera writes its own clock ("2026:10:13 09:21:05"), without a time zone.
            'taken_at' => $this->moment(isset($exif['EXIF']['DateTimeOriginal']) ? preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', (string) $exif['EXIF']['DateTimeOriginal']) : null),
            'location_source' => self::FROM_PHOTO,
        ];
    }

    private function valid(mixed $latitude, mixed $longitude): bool
    {
        return is_numeric($latitude) && is_numeric($longitude)
            && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180
            // 0, 0 is what a phone without a fix reports, not a place in the Gulf of Guinea.
            && ! ((float) $latitude === 0.0 && (float) $longitude === 0.0);
    }

    /** Degrees, minutes, seconds as EXIF stores them ("0/1", "20/1", "1779/100") to decimal degrees. */
    private function degrees(mixed $parts, mixed $reference): ?float
    {
        if (! is_array($parts) || count($parts) < 3) {
            return null;
        }

        $values = array_map(fn ($part) => $this->rational($part), array_slice(array_values($parts), 0, 3));

        if (in_array(null, $values, true)) {
            return null;
        }

        $decimal = $values[0] + $values[1] / 60 + $values[2] / 3600;

        return in_array(strtoupper(trim((string) $reference)), ['S', 'W'], true) ? -$decimal : $decimal;
    }

    private function rational(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (! is_string($value) || ! str_contains($value, '/')) {
            return null;
        }

        [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '1');

        return is_numeric($numerator) && is_numeric($denominator) && (float) $denominator != 0.0 ? (float) $numerator / (float) $denominator : null;
    }

    /** A moment in the application's time zone, never in the future; null when it cannot be read. */
    private function moment(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            $moment = Carbon::parse((string) $value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return ($moment->isAfter(now()->addMinutes(5)) ? now() : $moment)->toDateTimeString();
    }
}
