<?php

namespace Tests;

use App\Models\Outage;
use App\Models\OutagePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The tests rebuild their database from nothing, so it may only ever be a throw-away one: the
     * in-memory SQLite database (phpunit.xml), or a PostgreSQL database named *_test on this machine
     * (phpunit.pgsql.xml — PostgreSQL like the production server). With a cached configuration
     * (`php artisan optimize` / `config:cache`) the phpunit settings are ignored and the tests would
     * empty the real database — that happened on a development machine on 2026-10-10.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        $host = (string) $app['config']->get("database.connections.{$connection}.host");

        $throwAway = ($connection === 'sqlite' && $database === ':memory:')
            || ($connection === 'pgsql' && str_ends_with($database, '_test') && in_array($host, ['127.0.0.1', 'localhost', '::1'], true));

        if ($app->configurationIsCached() || ! $throwAway) {
            throw new \RuntimeException(
                'Refusing to run the tests: they would use the database "'.$database.'" ('.$connection.') instead of a throw-away one '
                .'(SQLite in memory, or PostgreSQL *_test on this machine). Run "php artisan optimize:clear" first — a cached configuration overrides phpunit.xml.'
            );
        }

        // Cached routes are the routes of the day they were cached: a new one would simply not exist.
        if ($app->routesAreCached()) {
            throw new \RuntimeException('Refusing to run the tests against cached routes. Run "php artisan optimize:clear" first.');
        }

        return $app;
    }

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
