<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-computed "digital twin" statistics, rebuilt by `php artisan twin:build`.
 * They are aggregates over historical records (no per-student rows), so pages
 * read a few small rows instead of scanning millions of academic records.
 */
return new class extends Migration
{
    public function up(): void
    {
        // How students "like you" fared two terms after a decision, vs similar students who didn't make it.
        Schema::create('twin_decision_stats', function (Blueprint $table) {
            $table->id();
            $table->string('decision', 16);      // drop | light | fail
            $table->string('phase', 8);          // early | mid | late
            $table->string('gpa_band', 8);       // new | g1..g5
            $table->string('lane', 8);           // on | slight | behind
            $table->unsignedInteger('n_with');
            $table->float('delayed_with');       // share delayed a semester or more two terms later
            $table->float('gap_with');           // average hours behind plan two terms later
            $table->unsignedInteger('n_without');
            $table->float('delayed_without');
            $table->float('gap_without');
            $table->unique(['decision', 'phase', 'gpa_band', 'lane']);
        });

        // Per course, for students in the same GPA band when they took it.
        Schema::create('twin_course_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('gpa_band', 8);
            $table->unsignedInteger('n_taken');
            $table->float('pass_rate');
            $table->float('avg_points')->nullable();
            $table->unsignedInteger('n_dropped');
            $table->float('delayed_if_dropped')->nullable();
            $table->float('delayed_if_kept')->nullable();
            $table->unique(['course_id', 'gpa_band']);
        });

        // Baseline outlook (no decision) per cohort cell, used by the status card.
        Schema::create('twin_cohorts', function (Blueprint $table) {
            $table->id();
            $table->string('phase', 8);
            $table->string('gpa_band', 8);
            $table->string('lane', 8);
            $table->unsignedInteger('n');
            $table->float('delayed');
            $table->float('gap');
            $table->unique(['phase', 'gpa_band', 'lane']);
        });

        Schema::create('twin_meta', function (Blueprint $table) {
            $table->string('key', 32)->primary();
            $table->json('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twin_meta');
        Schema::dropIfExists('twin_cohorts');
        Schema::dropIfExists('twin_course_stats');
        Schema::dropIfExists('twin_decision_stats');
    }
};
