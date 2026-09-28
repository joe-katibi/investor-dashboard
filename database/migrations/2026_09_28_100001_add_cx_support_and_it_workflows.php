<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three workflow additions:
 *  - NOC reject → Customer Experience (Tier II) callback / site visit follow-up
 *  - Support technician (drop cable → customer premises) requested on the same ticket, in parallel
 *  - System outages reviewed by IT first, escalated to NOC when core / network, confirmed by IT
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('major_outage_types', function (Blueprint $table) {
            $table->boolean('requires_it_review')->default(false)->after('uses_topology');
        });

        Schema::table('outages', function (Blueprint $table) {
            $table->dateTime('cx_followed_up_at')->nullable()->after('noc_rejected_by');
            $table->dateTime('it_escalated_to_noc_at')->nullable()->after('resolved_by');
            $table->foreignId('it_escalated_by')->nullable()->after('it_escalated_to_noc_at')->constrained('users')->nullOnDelete();
            $table->dateTime('noc_returned_to_it_at')->nullable()->after('it_escalated_by');
            $table->foreignId('noc_returned_by')->nullable()->after('noc_returned_to_it_at')->constrained('users')->nullOnDelete();
            $table->dateTime('it_confirmed_at')->nullable()->after('noc_returned_by');
            $table->foreignId('it_confirmed_by')->nullable()->after('it_confirmed_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('outage_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('called_at');
            $table->string('call_outcome', 40);
            $table->text('callback_notes');
            $table->boolean('site_visit_required')->default(false);
            $table->dateTime('site_visit_at')->nullable();
            $table->string('site_visit_reference', 100)->nullable();
            $table->text('site_visit_notes')->nullable();
            $table->boolean('closed_ticket')->default(false);
            $table->timestamps();
            $table->index(['outage_id', 'created_at']);
            $table->index(['site_visit_required', 'site_visit_at']);
        });

        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('work_required');
            $table->string('customer_details')->nullable();
            $table->string('location')->nullable();
            $table->string('status', 20)->default('open')->index(); // open | completed | cancelled
            $table->foreignId('queue_entry_id')->nullable()->constrained('outage_queue_entries')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['outage_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('outage_follow_ups');

        Schema::table('outages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('it_confirmed_by');
            $table->dropConstrainedForeignId('noc_returned_by');
            $table->dropConstrainedForeignId('it_escalated_by');
            $table->dropColumn(['cx_followed_up_at', 'it_escalated_to_noc_at', 'noc_returned_to_it_at', 'it_confirmed_at']);
        });

        Schema::table('major_outage_types', function (Blueprint $table) {
            $table->dropColumn('requires_it_review');
        });
    }
};
