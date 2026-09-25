<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIFO assignment engine (spec §27.10). Assignment rows are append-only records of each
 * decision; the snapshot columns keep history stable when configuration later changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('queue_type', 30)->index();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->restrictOnDelete();
            // Optional routing matchers; null matches any.
            $table->foreignId('major_outage_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vertical_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('required_permission', 80)->nullable();
            $table->string('assignment_mode', 20)->default('auto');
            $table->unsignedInteger('ack_timeout_minutes')->nullable();
            $table->unsignedSmallInteger('escalate_after_attempts')->default(3);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outage_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queue_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('queue_type', 30);
            $table->foreignId('priority_id')->nullable()->constrained()->nullOnDelete();
            // Denormalised rank so ordering is a single indexed scan; refreshed on PriorityChanged.
            $table->unsignedSmallInteger('priority_rank')->default(999);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('sub_team_id')->nullable()->constrained('teams')->nullOnDelete();
            // Exact FIFO time, microsecond precision; the id breaks any remaining tie.
            $table->dateTime('queued_at', 6);
            $table->dateTime('eligible_from')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            // Users who rejected or timed out on this entry are not offered it again.
            $table->json('rejected_user_ids')->nullable();
            // Set when requeue-on-reject/timeout is disabled: the entry waits for a manager.
            $table->boolean('auto_assign_blocked')->default(false);
            $table->dateTime('escalated_at')->nullable();
            $table->timestamps();
            $table->index(['queue_type', 'status', 'priority_rank', 'queued_at'], 'queue_entries_fifo_index');
            $table->index(['outage_id', 'status']);
        });

        Schema::create('outage_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queue_entry_id')->nullable()->constrained('outage_queue_entries')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('sub_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('queue_type', 30);
            $table->string('assignment_type', 20)->default('auto_fifo');
            $table->unsignedInteger('queue_position')->nullable();
            $table->foreignId('priority_id')->nullable()->constrained()->nullOnDelete();
            $table->string('priority_name', 50)->nullable();
            $table->dateTime('queued_at', 6)->nullable();
            $table->dateTime('assigned_at');
            $table->dateTime('ack_due_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->dateTime('timed_out_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 20)->default('assigned');
            $table->text('assignment_reason')->nullable();
            $table->text('release_reason')->nullable();
            // Eligible-user set and the ordering used — explains "why this user".
            $table->json('decision_meta')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'ack_due_at']);
            $table->index(['outage_id', 'queue_type']);
        });

        Schema::create('outage_assignment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('outage_assignments')->nullOnDelete();
            $table->string('queue_type', 30)->nullable();
            $table->foreignId('previous_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->text('reason')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 80)->default('user');
            $table->dateTime('performed_at');
            $table->timestamps();
            $table->index(['outage_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outage_assignment_histories');
        Schema::dropIfExists('outage_assignments');
        Schema::dropIfExists('outage_queue_entries');
        Schema::dropIfExists('queue_definitions');
    }
};
