<?php

namespace Database\Seeders;

use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * The 4G power vendors (tower companies). Created without contact details: set each vendor's
 * e-mail, CC addresses and contact person under 4G NOC → Vendors before the first power ticket —
 * until then the automatic vendor e-mail cannot be sent. More vendors are added on that screen.
 */
class VendorSeeder extends Seeder
{
    public const VENDORS = ['ATC', 'TOA', 'UTEL'];

    public function run(): void
    {
        foreach (self::VENDORS as $code) {
            Vendor::query()->firstOrCreate(['code' => $code], [
                'name' => $code,
                'vendor_type' => 'power',
                'description' => 'Tower company / power vendor.',
                'is_active' => true,
            ]);
        }
    }
}
