<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/** Affected services (spec §6). Requires VerticalSeeder. */
class ServiceSeeder extends Seeder
{
    public const SERVICES = [
        Vertical::FTTH => ['Internet', 'Voice (VoIP)', 'IPTV', 'Enterprise Fibre'],
        Vertical::MOBILITY_4G => ['Mobile Data', 'Voice', 'SMS', 'Fixed Wireless (MiFi)'],
        // Cross-vertical services (no vertical)
        '' => ['CRM', 'Call Center', 'Dialer', 'Billing (Savbill)', 'International Transit (SEACOM)'],
    ];

    public function run(): void
    {
        foreach (self::SERVICES as $verticalCode => $names) {
            $verticalId = $verticalCode === '' ? null : Vertical::query()->where('code', $verticalCode)->value('id');

            foreach ($names as $name) {
                Service::query()->firstOrCreate(['name' => $name, 'vertical_id' => $verticalId], ['is_active' => true]);
            }
        }
    }
}
