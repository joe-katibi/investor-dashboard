<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable master data. Column types are deliberately limited to those that
 * behave identically on MySQL and PostgreSQL (no enums, no unsigned-only logic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verticals', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('vertical_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['name', 'vertical_id']);
        });

        Schema::create('major_outage_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->boolean('requires_noc_confirmation')->default(true);
            $table->boolean('requires_infrastructure')->default(true);
            $table->boolean('uses_topology')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outage_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->foreignId('major_outage_type_id')->constrained()->restrictOnDelete();
            $table->string('prefix', 10);
            $table->boolean('is_planned')->default(false);
            $table->boolean('is_emergency')->default(false);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['major_outage_type_id', 'name']);
        });

        Schema::create('outage_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            // Workflow statuses carry a stable code the engine relies on; custom statuses leave it null.
            $table->string('code', 50)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('color', 20)->default('gray');
            $table->boolean('prompt_final_reason')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->boolean('is_resolved')->default(false);
            $table->boolean('pauses_sla')->default(false);
            $table->boolean('is_manual_selectable')->default(false);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('code', 30)->unique();
            // Higher severity wins when several impact rules match.
            $table->unsignedSmallInteger('severity')->default(1);
            // Configurable FIFO queue order: rank 1 is served first.
            $table->unsignedSmallInteger('queue_rank')->default(1);
            $table->unsignedInteger('min_customers')->nullable();
            $table->unsignedInteger('max_customers')->nullable();
            $table->string('color', 20)->default('gray');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('priorities');
        Schema::dropIfExists('outage_statuses');
        Schema::dropIfExists('outage_types');
        Schema::dropIfExists('major_outage_types');
        Schema::dropIfExists('services');
        Schema::dropIfExists('verticals');
    }
};
