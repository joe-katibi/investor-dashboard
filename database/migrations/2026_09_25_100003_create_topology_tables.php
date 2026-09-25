<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OLT -> FDT -> Slot -> Port. Parents use restrictOnDelete so topology that is
 * referenced by history can only ever be deactivated, never removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 60)->nullable();
            $table->string('location')->nullable();
            $table->foreignId('vertical_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fdts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['olt_id', 'name']);
            $table->index(['olt_id', 'is_active']);
        });

        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fdt_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fdt_id', 'name']);
            $table->index(['fdt_id', 'is_active']);
        });

        Schema::create('ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['slot_id', 'name']);
            $table->index(['slot_id', 'is_active']);
        });

        // Optional team coverage: when a team lists OLTs, it is only eligible for outages on them.
        Schema::create('olt_team', function (Blueprint $table) {
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->primary(['olt_id', 'team_id']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_team');
        Schema::dropIfExists('ports');
        Schema::dropIfExists('slots');
        Schema::dropIfExists('fdts');
        Schema::dropIfExists('olts');
    }
};
