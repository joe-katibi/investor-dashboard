<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 4G NOC module:
 *  - topology: eNodeB sites and transmission nodes (RSG → ASG → CSG → SSG)
 *  - vendors (records, not users) with manually captured vendor updates
 *  - 4G outage types with their own workflow (NOC → engineer → NOC, or NOC → vendor → NOC)
 *  - impact rules per vertical, so FTTH (customers) and 4G (eNodeBs / SSGs / ASGs / RSGs) rate severity separately
 *  - attachments, reopening history and editable vendor e-mail templates
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('code', 30)->unique();
            $table->string('vendor_type', 30)->default('power')->index();
            $table->string('contact_person', 150)->nullable();
            $table->string('email')->nullable();
            $table->json('cc_emails')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('enodebs', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 30)->unique();
            $table->string('name', 150)->index();
            $table->string('tower_company', 30)->nullable()->index();
            $table->string('tower_co_site_id', 60)->nullable();
            // Tower company that powers the site: the default vendor of a power outage there.
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->string('bracket_height', 40)->nullable();
            $table->string('tower_height', 40)->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('transmission_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->index(); // RSG | ASG | CSG | SSG
            $table->string('code', 40)->unique(); // RSG-1, ASG-11, CSG-111, SSG-1111
            $table->string('name', 190);
            $table->foreignId('parent_id')->nullable()->constrained('transmission_nodes')->restrictOnDelete();
            // SSG: the eNodeB site it carries.
            $table->foreignId('enodeb_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hostname', 120)->nullable()->unique();
            $table->string('management_ip', 45)->nullable();
            $table->string('gateway_ip', 45)->nullable();
            $table->string('equipment', 60)->nullable();
            $table->string('site_name', 150)->nullable();
            $table->string('tower_company', 30)->nullable();
            $table->string('tower_co_site_id', 60)->nullable();
            $table->string('status_note')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['parent_id', 'is_active']);
        });

        // Impact / priority rules are per vertical: FTTH rates by customers, 4G by network elements.
        Schema::table('priorities', function (Blueprint $table) {
            $table->foreignId('vertical_id')->nullable()->after('code')->constrained()->restrictOnDelete();
        });

        if ($ftth = DB::table('verticals')->where('code', 'FTTH')->value('id')) {
            DB::table('priorities')->whereNull('vertical_id')->update(['vertical_id' => $ftth]);
        }

        Schema::table('outage_types', function (Blueprint $table) {
            $table->foreignId('vertical_id')->nullable()->after('major_outage_type_id')->constrained()->restrictOnDelete();
            $table->string('workflow', 30)->nullable()->after('maintenance_kind'); // null = standard | mobility_internal | mobility_vendor
            $table->string('fault_domain', 30)->nullable()->after('workflow');     // enodeb | transmission | power
        });

        Schema::table('outage_statuses', function (Blueprint $table) {
            $table->string('workflow', 30)->nullable()->after('code')->index(); // null = standard | mobility
        });

        Schema::table('sla_policies', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('service_id')->constrained()->restrictOnDelete();
        });

        Schema::table('outage_updates', function (Blueprint $table) {
            $table->string('actor_type', 20)->nullable()->after('type'); // System | NOC | Engineer | Technician
        });

        Schema::table('outages', function (Blueprint $table) {
            $table->string('workflow', 30)->nullable()->after('maintenance_kind')->index();
            $table->string('fault_domain', 30)->nullable()->after('workflow')->index();
            $table->foreignId('vendor_id')->nullable()->after('fault_domain')->constrained()->restrictOnDelete();
            $table->dateTime('detected_at')->nullable()->after('start_time');
            $table->string('transmission_link_id', 120)->nullable()->after('site_label');
            $table->string('transmission_type', 30)->nullable()->after('transmission_link_id');
            $table->text('service_impact')->nullable()->after('description');
            $table->text('root_cause')->nullable()->after('final_reason');
            $table->text('resolution')->nullable()->after('root_cause');
            $table->json('impact_counts')->nullable()->after('power_faults');

            $table->dateTime('acknowledged_at')->nullable()->after('resolved_by');
            $table->dateTime('work_started_at')->nullable()->after('acknowledged_at');
            $table->dateTime('vendor_notified_at')->nullable()->after('work_started_at');
            $table->dateTime('vendor_acknowledged_at')->nullable()->after('vendor_notified_at');
            $table->dateTime('vendor_last_update_at')->nullable()->after('vendor_acknowledged_at');
            $table->dateTime('vendor_next_update_at')->nullable()->after('vendor_last_update_at');
            $table->dateTime('vendor_eta_at')->nullable()->after('vendor_next_update_at');
            $table->dateTime('vendor_resolved_at')->nullable()->after('vendor_eta_at');
            $table->unsignedTinyInteger('vendor_escalation_level')->default(0)->after('vendor_resolved_at');
            $table->dateTime('vendor_escalated_at')->nullable()->after('vendor_escalation_level');
            $table->dateTime('escalated_at')->nullable()->after('vendor_escalated_at');
            $table->foreignId('escalated_by')->nullable()->after('escalated_at')->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable()->after('escalated_by');
            $table->unsignedSmallInteger('reopen_count')->default(0)->after('reopened_at');
            $table->dateTime('cancelled_at')->nullable()->after('reopen_count');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('outage_enodeb', function (Blueprint $table) {
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enodeb_id')->constrained()->restrictOnDelete();
            $table->primary(['outage_id', 'enodeb_id']);
            $table->index('enodeb_id');
        });

        Schema::create('outage_transmission_node', function (Blueprint $table) {
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transmission_node_id')->constrained()->restrictOnDelete();
            $table->primary(['outage_id', 'transmission_node_id']);
            $table->index('transmission_node_id');
        });

        Schema::create('vendor_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // NOC user who captured it
            $table->dateTime('occurred_at');
            $table->string('contact_person', 150)->nullable();
            $table->string('method', 30);         // email | phone | whatsapp | other
            $table->string('vendor_status', 40);
            $table->dateTime('eta_at')->nullable();
            $table->dateTime('next_update_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->index(['outage_id', 'occurred_at']);
            $table->index(['vendor_id', 'occurred_at']);
        });

        Schema::create('outage_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_update_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index('outage_id');
        });

        Schema::create('outage_reopenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason');
            // The closure being undone.
            $table->foreignId('previous_status_id')->nullable()->constrained('outage_statuses')->nullOnDelete();
            $table->dateTime('previous_closed_at')->nullable();
            $table->foreignId('previous_closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('previous_end_time')->nullable();
            $table->string('previous_sla_state', 20)->nullable();
            $table->text('previous_root_cause')->nullable();
            $table->text('previous_resolution')->nullable();
            $table->timestamps();
            $table->index('outage_id');
        });

        Schema::create('impact_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('vertical_id')->constrained()->restrictOnDelete();
            $table->foreignId('outage_type_id')->nullable()->constrained()->restrictOnDelete(); // null = any type of the vertical
            $table->string('metric', 20);          // enodebs | cells | ssg | csg | asg | rsg | customers
            $table->unsignedInteger('min_value')->default(1);
            $table->unsignedInteger('max_value')->nullable();
            $table->foreignId('priority_id')->constrained()->restrictOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vendor_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('trigger', 30)->index();
            $table->foreignId('outage_type_id')->nullable()->constrained()->restrictOnDelete(); // null = any vendor ticket
            $table->boolean('send_to_vendor')->default(true);
            $table->boolean('cc_vendor_cc')->default(true);
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_templates');
        Schema::dropIfExists('impact_rules');
        Schema::dropIfExists('outage_reopenings');
        Schema::dropIfExists('outage_attachments');
        Schema::dropIfExists('vendor_updates');
        Schema::dropIfExists('outage_transmission_node');
        Schema::dropIfExists('outage_enodeb');

        Schema::table('outages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropIndex(['workflow']);
            $table->dropIndex(['fault_domain']);
            $table->dropColumn([
                'workflow', 'fault_domain', 'detected_at', 'transmission_link_id', 'transmission_type', 'service_impact', 'root_cause',
                'resolution', 'impact_counts', 'acknowledged_at', 'work_started_at', 'vendor_notified_at', 'vendor_acknowledged_at',
                'vendor_last_update_at', 'vendor_next_update_at', 'vendor_eta_at', 'vendor_resolved_at', 'vendor_escalation_level',
                'vendor_escalated_at', 'escalated_at', 'reopened_at', 'reopen_count', 'cancelled_at',
            ]);
        });

        Schema::table('outage_updates', fn (Blueprint $table) => $table->dropColumn('actor_type'));
        Schema::table('sla_policies', fn (Blueprint $table) => $table->dropConstrainedForeignId('vendor_id'));

        Schema::table('outage_statuses', function (Blueprint $table) {
            $table->dropIndex(['workflow']);
            $table->dropColumn('workflow');
        });

        Schema::table('outage_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vertical_id');
            $table->dropColumn(['workflow', 'fault_domain']);
        });

        Schema::table('priorities', fn (Blueprint $table) => $table->dropConstrainedForeignId('vertical_id'));

        Schema::dropIfExists('transmission_nodes');
        Schema::dropIfExists('enodebs');
        Schema::dropIfExists('vendors');
    }
};
