<?php

use Database\Seeders\CustomWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow configuration, stage 2: flows an administrator builds from the screen.
 *
 *   custom_flows        the flow: name, ticket prefix, who may raise a ticket, its SLA
 *   custom_flow_steps   its steps in order: who works each (a team), how it is given out, the times
 *   custom_tickets      one per ticket (an Outage row with workflow "custom"): the flow, and a copy
 *                       of the steps as they were when the ticket was raised — editing a flow later
 *                       never changes a ticket that is already on its way
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_flows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('prefix', 8)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sla_minutes')->nullable();          // whole ticket; empty = the fallback of FIFO & SLA Settings
            $table->json('raise_roles')->nullable();                     // role names; empty = everyone who may raise custom tickets
            $table->boolean('requires_resolution')->default(true);       // a closing note is needed at the last step
            $table->unsignedInteger('version')->default(1);              // goes up with every change of the flow or its steps
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('custom_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('name', 120);
            $table->text('instructions')->nullable();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('assignment_mode', 10)->default('auto');      // auto | manual
            $table->unsignedInteger('target_minutes')->nullable();       // the step's own SLA target
            $table->boolean('can_send_back')->default(true);             // may return the ticket to the step before
            $table->boolean('note_required')->default(false);            // a note is needed to complete the step
            $table->timestamps();
            $table->index(['custom_flow_id', 'position']);
        });

        Schema::create('custom_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('custom_flow_id')->nullable()->constrained()->nullOnDelete();
            $table->string('flow_name', 120);
            $table->unsignedInteger('flow_version')->default(1);
            $table->json('steps');                                       // the steps as they were when the ticket was raised
            $table->unsignedInteger('step_index')->default(0);
            $table->dateTime('step_since')->nullable();
            $table->unsignedInteger('sent_back_count')->default(0);
            $table->timestamps();
        });

        Schema::table('outage_queue_entries', function (Blueprint $table) {
            // The step of a custom flow the entry is for ("Finance approval"), shown instead of the generic queue name.
            $table->string('step_name', 120)->nullable()->after('queue_type');
        });

        // An installation in use gets the reference rows (ticket type, statuses, permissions) now;
        // a fresh one gets them from the seeders.
        if (DB::table('outage_statuses')->exists()) {
            (new CustomWorkflowSeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::table('outage_queue_entries', fn (Blueprint $table) => $table->dropColumn('step_name'));
        Schema::dropIfExists('custom_tickets');
        Schema::dropIfExists('custom_flow_steps');
        Schema::dropIfExists('custom_flows');
    }
};
