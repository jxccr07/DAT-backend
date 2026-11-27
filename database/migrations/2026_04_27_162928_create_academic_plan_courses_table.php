<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_plan_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_plan_id')->constrained('academic_plans')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedTinyInteger('suggested_semester');
            $table->timestamps();

            $table->unique(['academic_plan_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_plan_courses');
    }
};
