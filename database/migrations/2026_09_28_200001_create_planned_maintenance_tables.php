<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planned maintenance: Infrastructure raises a request, NOC completes the Maintenance & Change
 * Request Form (CRF) and collects sign-offs, then the work follows the normal Infrastructure →
 * NOC verification path and CX closes it once customers are told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->unique()->constrained()->cascadeOnDelete();

            // 1. General maintenance details
            $table->string('initiator', 150)->nullable();
            $table->string('department', 150)->nullable();
            $table->string('in_charge', 150)->nullable();
            $table->dateTime('scheduled_start');
            $table->dateTime('scheduled_end');
            $table->string('escalation_time', 100)->nullable();
            $table->string('rollback_time', 100)->nullable();
            $table->string('emergency_contact', 100)->nullable();

            // 2. Risk rating, 3. technical scope & fallback, 4. operational checklist
            $table->string('risk_tier', 20)->default('medium');
            $table->text('work_description');
            $table->text('fallback_plan')->nullable();
            $table->text('prior_tests')->nullable();
            $table->text('risks_mitigation')->nullable();
            $table->json('checklist')->nullable();
            $table->json('stakeholder_emails')->nullable();
            $table->string('customer_list_path')->nullable();
            $table->string('customer_list_name')->nullable();

            // Sign-off
            $table->string('status', 30)->default('draft')->index(); // draft | pending_signoff | changes_requested | approved | cancelled
            $table->dateTime('sent_for_signoff_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('started_notified_at')->nullable();
            $table->unsignedSmallInteger('reschedule_count')->default(0);

            // 5. Post-maintenance outcome
            $table->string('final_status', 20)->nullable(); // successful | rolled_back | partial
            $table->text('outcome_details')->nullable();
            $table->dateTime('customers_notified_at')->nullable();
            $table->foreignId('customers_notified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('notification_channels')->nullable();
            $table->text('customer_notification_note')->nullable();

            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'scheduled_start']);
        });

        // 6. Sign-offs & authorisations
        Schema::create('change_request_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('role_label', 150);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150)->nullable();
            $table->string('email');
            $table->string('token_hash', 64)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | changes_requested
            $table->string('signed_name', 150)->nullable();
            $table->longText('signature_image')->nullable(); // PNG data URI drawn on the sign page
            $table->text('comment')->nullable();
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent')->nullable();
            $table->timestamps();
            $table->index(['change_request_id', 'status']);
        });

        Schema::create('change_request_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained()->cascadeOnDelete();
            $table->dateTime('from_start');
            $table->dateTime('from_end');
            $table->dateTime('to_start');
            $table->dateTime('to_end');
            $table->text('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Editable maintenance e-mails, each with its own trigger ("when to send").
        Schema::create('maintenance_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('trigger', 30)->index();
            $table->unsignedInteger('offset_minutes')->nullable(); // for "before_start"
            $table->boolean('send_to_stakeholders')->default(true);
            $table->boolean('send_to_signatories')->default(false);
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->boolean('attach_crf')->default(false);
            $table->string('subject');
            $table->text('body');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One row per timed send (reminder / before-start / started) so each goes out once per window.
        Schema::create('maintenance_mail_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 80);
            $table->dateTime('window_start');
            $table->string('status', 20)->default('sent'); // sent | skipped
            $table->timestamps();
            $table->unique(['change_request_id', 'slot', 'window_start'], 'maintenance_mail_sends_unique');
        });

        // Extra delivery data for templated maintenance e-mails (rendered HTML, CRF attachment).
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', fn (Blueprint $table) => $table->dropColumn('meta'));
        Schema::dropIfExists('maintenance_mail_sends');
        Schema::dropIfExists('maintenance_templates');
        Schema::dropIfExists('change_request_reschedules');
        Schema::dropIfExists('change_request_signatures');
        Schema::dropIfExists('change_requests');
    }
};
