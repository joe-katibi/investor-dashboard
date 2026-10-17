<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Services\Mobile\MobileProfile;
use App\Services\Mobile\TicketScopes;
use Tests\TestCase;

class MobileGuideTest extends TestCase
{
    public function test_the_guide_opens_without_signing_in(): void
    {
        $this->get('/mobile-guide')
            ->assertOk()
            ->assertSee('OMS mobile app — user guide')
            ->assertSee('Signing in')
            ->assertSee('Messages and what to do');
    }

    /** The guide names the dashboard tiles and availability options: it must follow when they change. */
    public function test_the_guide_names_every_dashboard_list_and_availability(): void
    {
        $page = $this->get(route('mobile.guide'))->assertOk();

        foreach ([MobileProfile::DISPATCH, MobileProfile::TECHNICIAN] as $role) {
            foreach (TicketScopes::labels($role) as $label) {
                $page->assertSee($label);
            }
        }

        foreach (AvailabilityStatus::cases() as $status) {
            $page->assertSee($status->getLabel());
        }
    }
}
