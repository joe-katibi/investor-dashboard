<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10)->unique();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // Null matchers mean "any"; the most specific active policy wins.
            $table->foreignId('major_outage_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('outage_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vertical_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('resolution_minutes');
            $table->unsignedInteger('pre_breach_minutes')->default(30);
            $table->unsignedInteger('ack_timeout_minutes')->nullable();
            $table->unsignedInteger('escalation_minutes')->nullable();
            $table->json('escalation_roles')->nullable();
            $table->json('notify_roles')->nullable();
            $table->json('notify_user_ids')->nullable();
            $table->json('notify_emails')->nullable();
            $table->boolean('notify_credit')->default(true);
            $table->boolean('notify_sms')->default(true);
            $table->boolean('allow_pause')->default(true);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outages', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 30)->unique();
            $table->string('title');
            $table->foreignId('major_outage_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('outage_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('vertical_id')->constrained()->restrictOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('priority_is_manual')->default(false);
            $table->foreignId('status_id')->constrained('outage_statuses')->restrictOnDelete();
            $table->boolean('is_closed')->default(false);
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('customers_affected')->default(0);
            $table->unsignedInteger('sites_down')->default(0);
            $table->unsignedInteger('cells_down')->default(0);
            $table->unsignedInteger('power_faults')->default(0);
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();

            // Ownership
            $table->string('team_type', 20)->nullable();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignId('sub_team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('current_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('current_queue', 30)->nullable();

            // SLA
            $table->foreignId('sla_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('sla_due_at')->nullable();
            $table->dateTime('sla_pre_breach_at')->nullable();
            $table->string('sla_state', 20)->default('compliant');
            $table->dateTime('sla_paused_at')->nullable();
            $table->unsignedInteger('sla_paused_seconds')->default(0);
            $table->dateTime('sla_pre_breach_notified_at')->nullable();
            $table->dateTime('sla_breach_notified_at')->nullable();
            $table->dateTime('sla_escalated_at')->nullable();

            // Customer communication — explicit YES/NO fields with audit (spec §18)
            $table->boolean('credit_shared')->default(false);
            $table->foreignId('credit_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('credit_updated_at')->nullable();
            $table->boolean('sms_sent')->default(false);
            $table->foreignId('sms_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sms_updated_at')->nullable();

            $table->text('final_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('latest_update')->nullable();

            // Lifecycle timestamps
            $table->dateTime('noc_escalated_at')->nullable();
            $table->dateTime('noc_confirmed_at')->nullable();
            $table->foreignId('noc_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('noc_rejected_at')->nullable();
            $table->foreignId('noc_rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('infra_escalated_at')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('restore_confirmed_at')->nullable();
            $table->foreignId('restore_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_closed', 'major_outage_type_id']);
            $table->index(['is_closed', 'priority_id']);
            $table->index(['is_closed', 'sla_state']);
            $table->index(['is_closed', 'sla_due_at']);
            $table->index('status_id');
            $table->index('start_time');
            $table->index('created_at');
            $table->index('resolved_at');
        });

        foreach (['olt', 'fdt', 'slot', 'port', 'service'] as $related) {
            Schema::create("outage_{$related}", function (Blueprint $table) use ($related) {
                $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
                $table->foreignId("{$related}_id")->constrained()->restrictOnDelete();
                $table->primary(['outage_id', "{$related}_id"]);
                $table->index("{$related}_id");
            });
        }

        Schema::create('outage_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('outage_statuses')->restrictOnDelete();
            $table->foreignId('to_status_id')->constrained('outage_statuses')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 80)->default('user');
            // Snapshot of priority/team/technician/impact at the moment of transition.
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->index(['outage_id', 'created_at']);
        });

        Schema::create('outage_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->default('note');
            $table->text('body');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->index(['outage_id', 'created_at']);
        });

        Schema::create('outage_flag_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->string('flag', 30);
            $table->boolean('old_value')->nullable();
            $table->boolean('new_value');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['outage_id', 'flag']);
        });

        Schema::create('sla_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sla_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30);
            $table->string('trigger', 80)->default('system');
            $table->json('meta')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['outage_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_events');
        Schema::dropIfExists('outage_flag_events');
        Schema::dropIfExists('outage_updates');
        Schema::dropIfExists('outage_status_histories');
        foreach (['service', 'port', 'slot', 'fdt', 'olt'] as $related) {
            Schema::dropIfExists("outage_{$related}");
        }
        Schema::dropIfExists('outages');
        Schema::dropIfExists('sla_policies');
        Schema::dropIfExists('ticket_sequences');
    }
};
