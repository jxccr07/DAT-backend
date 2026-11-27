<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_academic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->cascadeOnDelete();
            $table->enum('status', ['passed', 'deleted_course', 'failed', 'current_course']);
            $table->decimal('grade', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'course_id', 'semester_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_academic_records');
    }
};
