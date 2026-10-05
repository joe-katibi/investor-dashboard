<?php

namespace Tests;

use App\Models\Outage;
use App\Models\OutagePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The three site photos an Emergency / Regular outage is reported restored with, as if the
     * field team had taken them (tests of other things than the photos themselves).
     */
    protected function withSitePhotos(Outage $outage, ?User $by = null): Outage
    {
        foreach (array_keys(OutagePhoto::SITE_PHOTOS) as $position) {
            OutagePhoto::query()->create([
                'outage_id' => $outage->id,
                'stage' => OutagePhoto::SITE,
                'position' => $position,
                'path' => "outage-photos/test/{$outage->id}-{$position}.jpg",
                'original_name' => "{$position}.jpg",
                'taken_at' => now(),
                'source' => OutagePhoto::SOURCE_CAMERA,
                'user_id' => $by?->id,
            ]);
        }

        return $outage;
    }
}
