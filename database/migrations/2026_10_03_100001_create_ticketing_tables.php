<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer ticketing and site visits:
 *  - customers (linked to the FTTH topology: OLT → FDT → Slot → Port) and regions
 *  - configurable categories / subcategories with desk routing, visit types, final reasons, delay reasons
 *  - the ticket detail row of an outage record (customer tickets and site visits are Outage rows with
 *    their own workflow, so FIFO, SLA, notifications, timeline, attachments and audit are shared)
 *  - desk moves (escalation turnaround), structured events (TAT timeline) and customer follow-ups
 */
return new class extends Migration
{
    public function up(): void
    {
        $audit = function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        };

        Schema::create('regions', function (Blueprint $table) use ($audit) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $audit($table);
        });

        // An OLT belongs to a region; customers and tickets on it inherit that region.
        Schema::table('olts', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('vertical_id')->constrained()->nullOnDelete();
        });

        // Dispatchers / CX field support locked to regions only receive tickets of those regions.
        Schema::create('region_user', function (Blueprint $table) {
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['region_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('customers', function (Blueprint $table) use ($audit) {
            $table->id();
            $table->string('account_number', 60)->unique();
            $table->string('name', 190)->nullable()->index();
            $table->string('primary_phone', 40)->nullable()->index();
            $table->string('alt_phone_1', 40)->nullable();
            $table->string('alt_phone_2', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('landmark')->nullable();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('olt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fdt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('slot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('port_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bandwidth', 60)->nullable();
            // manual = typed in by an agent while raising a ticket; import = from the customer file.
            $table->string('source', 20)->default('manual')->index();
            $table->dateTime('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $audit($table);
            $table->index(['olt_id', 'fdt_id', 'slot_id', 'port_id'], 'customers_topology_index');
        });

        Schema::create('visit_types', function (Blueprint $table) use ($audit) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code_prefix', 10)->unique(); // INS, WiE, SUP, SHI
            $table->text('description')->nullable();
            $table->unsignedInteger('default_sla_minutes')->nullable();
            $table->foreignId('priority_id')->nullable()->constrained()->nullOnDelete();
            // FAT number, geolocation photo and speed-test photo before the technician can complete.
            $table->boolean('requires_evidence')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $audit($table);
        });

        Schema::create('ticket_categories', function (Blueprint $table) use ($audit) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $audit($table);
        });

        Schema::create('ticket_subcategories', function (Blueprint $table) use ($audit) {
            $table->id();
            $table->foreignId('ticket_category_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('code', 60)->unique();
            $table->text('description')->nullable();
            // Routing: the desk a new ticket goes to, who approves (refund / credit) and who then carries it out.
            $table->string('first_desk', 30);
            $table->string('approval_desk', 30)->nullable();
            $table->string('fulfilment_desk', 30)->nullable();
            $table->boolean('allows_site_visit')->default(true);
            $table->foreignId('visit_type_id')->nullable()->constrained()->nullOnDelete();
            // LOS-type issues: warn when the customer's OLT / FDT / slot / port has an ongoing outage.
            $table->boolean('check_outage')->default(false);
            $table->foreignId('priority_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sla_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $audit($table);
            $table->unique(['ticket_category_id', 'name']);
        });

        Schema::create('delay_reasons', function (Blueprint $table) use ($audit) {
            $table->id();
            $table->string('code', 10)->nullable()->unique();
            $table->string('name', 190)->unique();
            // Yes = we could have prevented it (drives the "preventable hours" figures).
            $table->boolean('within_control')->default(false);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $audit($table);
        });

        Schema::create('final_reasons', function (Blueprint $table) use ($audit) {
            $table->id();
            $table->string('name', 190)->unique();
            $table->text('description')->nullable();
            $table->string('applies_to', 20)->nullable(); // ticket | visit | null = both
            // The delay this outcome normally comes with (pre-selected when closing).
            $table->foreignId('delay_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $audit($table);
        });

        // Which desk may hand a ticket to which other desk.
        Schema::create('desk_routes', function (Blueprint $table) use ($audit) {
            $table->id();
            $table->string('from_desk', 30)->index();
            $table->string('to_desk', 30);
            $table->string('label', 120)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $audit($table);
            $table->unique(['from_desk', 'to_desk']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->unique()->constrained()->cascadeOnDelete();

            // Customer (copied onto the ticket, so history survives later changes to the customer record).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_number', 60)->nullable()->index();
            $table->string('customer_name', 190)->nullable();
            $table->string('calling_number', 40)->nullable();
            $table->string('primary_number', 40)->nullable();
            $table->string('alt_number_1', 40)->nullable();
            $table->string('alt_number_2', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('landmark')->nullable();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('olt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fdt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('slot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('port_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bandwidth', 60)->nullable();

            // What it is about
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('ticket_subcategories')->nullOnDelete();
            $table->foreignId('visit_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ticket_type', 30)->default('reactive')->index();
            $table->string('source', 30)->nullable();
            $table->string('external_reference', 60)->nullable()->index();
            // Site visit raised from a customer ticket; ticket affected by a network outage.
            $table->foreignId('parent_outage_id')->nullable()->constrained('outages')->nullOnDelete();
            $table->foreignId('linked_outage_id')->nullable()->constrained('outages')->nullOnDelete();
            $table->string('preferred_time', 120)->nullable();
            $table->dateTime('scheduled_at')->nullable()->index();

            // Desk (who has it now) and the hand-offs
            $table->string('home_desk', 30)->nullable();
            $table->string('desk', 30)->nullable()->index();
            $table->dateTime('desk_since')->nullable();
            $table->unsignedBigInteger('assignment_id')->nullable(); // the FIFO assignment last reflected on the ticket
            $table->dateTime('first_assigned_at')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->string('escalated_to', 30)->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedSmallInteger('escalation_count')->default(0);

            // Refund / credit approval
            $table->string('approval_state', 20)->nullable();
            $table->foreignId('approval_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approval_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->decimal('amount', 14, 2)->nullable();

            // Outcome
            $table->foreignId('delay_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('final_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->boolean('customer_confirmed')->nullable();

            // Site visit: completion evidence and materials
            $table->string('fat_number', 60)->nullable();
            $table->string('onu_type', 60)->nullable();
            $table->unsignedInteger('drop_cable_m')->nullable();
            $table->unsignedInteger('ethernet_m')->nullable();
            $table->string('patch_cord', 60)->nullable();
            $table->string('atb', 60)->nullable();
            $table->string('extender', 60)->nullable();

            // Site visit: field timeline
            $table->dateTime('en_route_at')->nullable();
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);

            // Team leader intervention (separate timer, ownership transfer)
            $table->foreignId('intervention_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('intervention_started_at')->nullable();
            $table->dateTime('intervention_ended_at')->nullable();
            $table->unsignedInteger('intervention_seconds')->default(0);

            // Customer follow-up (pending 9 hours+) and credit
            $table->dateTime('follow_up_due_at')->nullable()->index();
            $table->dateTime('followed_up_at')->nullable();
            $table->foreignId('followed_up_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('follow_up_outcome', 40)->nullable();
            $table->unsignedSmallInteger('follow_up_count')->default(0);
            $table->dateTime('credit_ready_at')->nullable()->index();
            $table->dateTime('credit_notified_at')->nullable();

            $table->dateTime('rescheduled_at')->nullable();
            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->dateTime('customer_sms_at')->nullable();
            $table->timestamps();

            $table->index(['olt_id', 'fdt_id', 'slot_id', 'port_id'], 'tickets_topology_index');
        });

        Schema::create('ticket_desk_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->string('from_desk', 30)->nullable();
            $table->string('to_desk', 30);
            $table->string('kind', 20)->default('route'); // route | return | approve | decline | assign | reopen
            $table->text('note')->nullable();
            $table->foreignId('delay_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('moved_at');
            // Set when the ticket comes back from to_desk: escalation turnaround = moved_at → returned_at.
            $table->dateTime('returned_at')->nullable();
            $table->timestamps();
            $table->index(['outage_id', 'moved_at']);
            $table->index(['to_desk', 'returned_at']);
        });

        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40)->index();
            $table->string('desk', 30)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['outage_id', 'occurred_at']);
        });

        Schema::create('ticket_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30)->default('callback'); // callback | pending | confirmation | field_support
            $table->string('outcome', 40);
            $table->text('notes')->nullable();
            $table->dateTime('called_at');
            $table->timestamps();
            $table->index(['outage_id', 'called_at']);
        });

        // geo_photo | speed_test | evidence (null = ordinary attachment)
        Schema::table('outage_attachments', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('vendor_update_id');
        });
    }

    public function down(): void
    {
        Schema::table('outage_attachments', fn (Blueprint $table) => $table->dropColumn('kind'));

        foreach ([
            'ticket_follow_ups', 'ticket_events', 'ticket_desk_moves', 'tickets', 'desk_routes', 'final_reasons', 'delay_reasons',
            'ticket_subcategories', 'ticket_categories', 'visit_types', 'customers', 'region_user',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('olts', fn (Blueprint $table) => $table->dropConstrainedForeignId('region_id'));
        Schema::dropIfExists('regions');
    }
};
