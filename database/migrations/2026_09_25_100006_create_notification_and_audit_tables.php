<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('event', 50)->index();
            $table->foreignId('major_outage_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('outage_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vertical_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('outage_statuses')->restrictOnDelete();
            $table->json('channels');
            $table->json('recipient_roles')->nullable();
            $table->json('recipient_user_ids')->nullable();
            $table->json('recipient_emails')->nullable();
            $table->json('recipient_phones')->nullable();
            $table->boolean('notify_assignee')->default(false);
            $table->boolean('notify_team_leaders')->default(false);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('outage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_template_id')->nullable();
            $table->string('event', 50);
            $table->string('channel', 20);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->string('trigger', 80)->default('system');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['outage_id', 'event']);
            $table->index('created_at');
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('report_type', 30)->default('custom');
            $table->string('schedule_type', 20)->default('interval');
            $table->unsignedInteger('interval_minutes')->nullable();
            $table->json('daily_times')->nullable();
            $table->string('cron_expression', 100)->nullable();
            $table->unsignedInteger('period_hours')->default(24);
            $table->json('to');
            $table->json('cc')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->boolean('attach_excel')->default(false);
            $table->text('description')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('next_run_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->foreign('email_template_id')->references('id')->on('email_templates')->nullOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 40);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 80)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
            $table->index('user_id');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
        Schema::table('notification_logs', fn (Blueprint $table) => $table->dropForeign(['email_template_id']));
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_rules');
    }
};
