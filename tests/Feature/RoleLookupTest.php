<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Maintenance\MaintenanceNotifier;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Role names kept in settings (SLA escalation, alert lists, credit roles, maintenance stakeholders)
 * can outlive the role: a renamed or deleted role is skipped instead of stopping the job that uses it.
 */
class RoleLookupTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    public function test_unknown_roles_are_skipped_and_no_role_matches_nobody(): void
    {
        $this->seedReference();
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        $this->makeUser('CX Frontline', 'CX');

        $this->assertSame([$tier2->id], User::query()->withAnyRole(['CX Tier II', 'Infrastructure Outage Dispatcher'])->pluck('id')->all());
        $this->assertSame([], User::query()->withAnyRole(['Infrastructure Outage Dispatcher'])->pluck('id')->all());
        $this->assertSame([], User::query()->withAnyRole([])->pluck('id')->all());
        $this->assertSame([], User::query()->withAnyRole(null)->pluck('id')->all());

        app(Settings::class)->set(['maintenance.stakeholder_roles' => ['Deleted Role', 'CX Tier II']]);
        $this->assertSame([$tier2->id], app(MaintenanceNotifier::class)->stakeholderUsers()->pluck('id')->all());
    }
}
