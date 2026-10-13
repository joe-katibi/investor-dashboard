<?php

namespace App\Services\Geo;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The name of a place and a small map of it, for the stamp on a geolocation photo.
 *
 * The phones ask OMS; OMS asks OpenStreetMap (the address from its Nominatim service, the map from
 * its tile server) — only a latitude and a longitude leave the building, never a ticket or a
 * customer. Answers are kept: the same spot is not asked for twice. It can be switched off under
 * Customer Care → Ticket Settings; the photo then carries the coordinates and the time alone.
 */
class PlaceLookup
{
    public const ZOOM = 16;

    public const TILE = 256;

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->bool('geo.lookup');
    }

    /**
     * @return array{place: ?string, address: ?string, map: ?array{png: string, pin_x: int, pin_y: int, size: int, credit: string}}
     */
    public function lookup(float $latitude, float $longitude): array
    {
        if (! $this->enabled()) {
            return ['place' => null, 'address' => null, 'map' => null];
        }

        return $this->names($latitude, $longitude) + ['map' => $this->map($latitude, $longitude)];
    }

    /** @return array{place: ?string, address: ?string} */
    private function names(float $latitude, float $longitude): array
    {
        // About eleven metres: the same premise gives the same answer.
        $key = 'oms:geo:place:'.round($latitude, 4).':'.round($longitude, 4);
        $empty = ['place' => null, 'address' => null];

        if (is_array($known = Cache::get($key))) {
            return $known;
        }

        try {
            $response = Http::timeout(8)->withHeaders($this->headers())->get(rtrim((string) config('oms.geo.nominatim_url'), '/').'/reverse', [
                'format' => 'jsonv2', 'lat' => $latitude, 'lon' => $longitude, 'zoom' => 18, 'addressdetails' => 1, 'accept-language' => 'en',
            ]);

            if (! $response->successful() || ! is_array($response->json('address'))) {
                return $empty;
            }

            $address = $response->json('address');
            $pick = fn (string ...$keys) => collect($keys)->map(fn (string $k) => $address[$k] ?? null)->first(fn ($value) => filled($value));

            $town = $pick('city', 'town', 'municipality', 'village', 'county');
            $street = trim(($address['house_number'] ?? '').' '.($pick('road', 'pedestrian', 'footway', 'path') ?? ''));
            $names = [
                'place' => $this->line([$town, $pick('state', 'region'), $address['country'] ?? null]),
                // "Plot 114 Owen Rd, Mulago, Kampala"; where the map knows no street, the neighbourhood says where: "Kitante, Wandegeya, Kampala".
                'address' => $this->line([$street, $address['neighbourhood'] ?? null, $pick('suburb', 'quarter', 'hamlet'), $town]),
            ];

            Cache::put($key, $names, now()->addDays(30));

            return $names;
        } catch (Throwable $e) {
            report($e);

            return $empty;
        }
    }

    /** The map tile the point lies on, and where on it the point is. */
    private function map(float $latitude, float $longitude): ?array
    {
        $n = 2 ** self::ZOOM;
        $x = ($longitude + 180) / 360 * $n;
        $radians = deg2rad(max(-85.0511, min(85.0511, $latitude)));
        $y = (1 - log(tan($radians) + 1 / cos($radians)) / M_PI) / 2 * $n;
        [$tileX, $tileY] = [(int) floor($x), (int) floor($y)];

        $disk = Storage::disk('local');
        $file = 'geo-tiles/'.self::ZOOM."/{$tileX}/{$tileY}.png";

        try {
            // Kept for a month, then fetched afresh: maps change, and a wrong answer does not stay for ever.
            if (! $disk->exists($file) || $disk->lastModified($file) < now()->subDays(30)->getTimestamp()) {
                $response = Http::timeout(8)->withHeaders($this->headers())
                    ->get(str_replace(['{z}', '{x}', '{y}'], [self::ZOOM, $tileX, $tileY], (string) config('oms.geo.tile_url')));

                // A picture, and of a sensible size: nothing else is passed on to the phones.
                if (! $response->successful() || ! str_starts_with($response->body(), "\x89PNG") || strlen($response->body()) > 400_000) {
                    return null;
                }

                $disk->put($file, $response->body());
            }

            return [
                'png' => base64_encode($disk->get($file)),
                'pin_x' => (int) round(($x - $tileX) * self::TILE),
                'pin_y' => (int) round(($y - $tileY) * self::TILE),
                'size' => self::TILE,
                'credit' => 'OpenStreetMap',
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** OpenStreetMap asks every application to say who it is. */
    private function headers(): array
    {
        return ['User-Agent' => (string) config('oms.geo.user_agent'), 'Accept-Language' => 'en'];
    }

    /** "Kampala, Central Region, Uganda": the parts that are there, once each. */
    private function line(array $parts): ?string
    {
        $line = collect($parts)->map(fn ($part) => trim((string) $part))->filter()->unique()->implode(', ');

        return $line !== '' ? mb_substr($line, 0, 120) : null;
    }
}
