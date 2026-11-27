<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - n_kept: size of the "kept this course" group, so the dialog can lead with the course's own numbers.
 * - What students who stayed on track did differently, per cohort ("what worked").
 * - A log of every "price of the decision" view and whether it ended in a drop:
 *   the system's own impact, measured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('twin_course_stats', function (Blueprint $table) {
            $table->unsignedInteger('n_kept')->default(0);
        });

        Schema::table('twin_cohorts', function (Blueprint $table) {
            $table->unsignedInteger('n_on_time')->default(0);
            $table->float('drop_on_time')->nullable();     // share who dropped a course that term
            $table->float('drop_delayed')->nullable();
            $table->float('light_on_time')->nullable();    // share with a light load
            $table->float('light_delayed')->nullable();
            $table->float('fail_on_time')->nullable();     // share who failed a course
            $table->float('fail_delayed')->nullable();
        });

        Schema::create('twin_decision_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('impact', 8)->nullable();      // small | medium | large, as shown
            $table->boolean('dropped')->default(false);   // did the student go ahead afterwards?
            $table->timestamps();
            $table->index(['student_id', 'course_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twin_decision_views');
        Schema::table('twin_cohorts', function (Blueprint $table) {
            $table->dropColumn(['n_on_time', 'drop_on_time', 'drop_delayed', 'light_on_time', 'light_delayed', 'fail_on_time', 'fail_delayed']);
        });
        Schema::table('twin_course_stats', function (Blueprint $table) {
            $table->dropColumn('n_kept');
        });
    }
};
