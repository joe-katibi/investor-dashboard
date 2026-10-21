<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The issue decides the kind of visit (ManagesTickets::createSiteVisit). "External Shifting" (made on the production
 * server) had none, so whoever booked it picked one — Shifting or Support. It is a Shifting visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $shifting = DB::table('visit_types')->where('code_prefix', 'SHI')->value('id');

        if ($shifting) {
            DB::table('ticket_subcategories')->whereRaw('lower(name) = ?', ['external shifting'])->whereNull('visit_type_id')
                ->update(['visit_type_id' => $shifting, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Left as it is: the kind of visit of an issue is edited under Customer Care → Ticket Subcategories.
    }
};
