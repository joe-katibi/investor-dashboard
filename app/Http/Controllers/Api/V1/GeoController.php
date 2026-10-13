<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Geo\PlaceLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a position is called and a small map of it, for the stamp the mobile app writes on a
 * geolocation photo. Empty answers (lookup switched off, or the map service not reachable) are
 * normal: the photo is then stamped with the coordinates and the time alone.
 */
class GeoController extends Controller
{
    public function __invoke(Request $request, PlaceLookup $places): JsonResponse
    {
        $position = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json($places->lookup((float) $position['lat'], (float) $position['lng']))
            ->header('Cache-Control', 'private, no-store');
    }
}
