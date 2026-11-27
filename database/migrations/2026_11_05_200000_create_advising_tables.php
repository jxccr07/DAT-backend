<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic advising: advisors, their students, alerts when a student makes a
 * costly decision, follow-up notes, and a per-student risk row so an advisor
 * can rank hundreds of students instantly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('student')->index();
            $table->foreignId('advisor_id')->nullable()->index()->constrained('users')->nullOnDelete();
        });

        // Current standing per student, refreshed by twin:build and after each registration change.
        Schema::create('twin_student_status', function (Blueprint $table) {
            $table->foreignId('student_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('term_no');
            $table->float('gpa')->nullable();
            $table->string('gpa_band', 8);
            $table->string('lane', 8);
            $table->unsignedSmallInteger('gap_hours');
            $table->unsignedSmallInteger('failed_pending');
            $table->unsignedSmallInteger('registered_hours');
            $table->float('risk')->nullable();   // share of similar students a semester+ behind two terms later
            $table->timestamp('updated_at')->nullable();
            $table->index('risk');
        });

        Schema::create('advisor_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 24);                 // costly_drop | light_load
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->json('details')->nullable();         // the numbers the student saw
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->index(['advisor_id', 'seen_at']);
        });

        Schema::create('advisor_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('contacted')->default(false);
            $table->timestamps();
            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_notes');
        Schema::dropIfExists('advisor_alerts');
        Schema::dropIfExists('twin_student_status');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advisor_id');
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
