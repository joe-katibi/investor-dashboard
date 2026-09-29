<?php

namespace App\Http\Controllers;

use App\Models\OutagePhoto;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Site photos are private: only signed-in users who may view the ticket can open them. */
class OutagePhotoController extends Controller
{
    public function __invoke(OutagePhoto $photo): StreamedResponse
    {
        Gate::authorize('view', $photo->outage);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, $photo->original_name, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
