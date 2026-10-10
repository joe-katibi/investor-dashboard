<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report exports prepared in the background: asking for an export only queues it, a worker writes
 * the file, and the person downloads it from Reporting → Report Downloads. A file is kept for six
 * hours and then removed together with its row (oms:prune-report-exports).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_key', 60);
            $table->string('title', 150);
            $table->string('format', 10);              // csv | xlsx | pdf
            $table->json('filters')->nullable();       // as chosen on the Reports page
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('status', 12)->default('queued'); // queued | running | ready | failed
            $table->string('path')->nullable();        // on the private disk
            $table->string('file_name', 200)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
