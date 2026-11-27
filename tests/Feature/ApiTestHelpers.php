<?php

use App\Models\AcademicPlan;
use App\Models\Course;
use App\Models\Semester;
use App\Models\Specialize;
use App\Models\User;

/**
 * Builds a small plan: 7 courses x 3 hours over levels 1..4, one active semester.
 *
 * @return array{student: User, plan: AcademicPlan, courses: \Illuminate\Support\Collection, semester: Semester, past: Semester}
 */
function academicWorld(): array
{
    $spec = Specialize::create(['name' => 'Computing']);
    $plan = AcademicPlan::create(['name' => 'CS Plan', 'program' => 'BSc', 'college' => 'Computing', 'major' => 'CS']);

    $courses = collect(range(1, 7))->map(fn (int $i) => Course::create([
        'name' => "Course {$i}", 'code' => sprintf('CS%03d', $i), 'specialization_id' => $spec->id,
        'level' => (int) ceil($i / 2), 'hours' => 3,
    ]));
    $courses->each(fn (Course $c, int $i) => $plan->courses()->attach($c->id, ['suggested_semester' => (int) ceil(($i + 1) / 2)]));

    $past = Semester::create(['name' => 'Past', 'academic_year' => '2025/2026', 'semester' => 'first',
        'starts_at' => '2025-08-31', 'ends_at' => '2026-01-10', 'is_active' => false]);
    $semester = Semester::create(['name' => 'Now', 'academic_year' => '2026/2027', 'semester' => 'first',
        'registration_starts_at' => now()->subDay(), 'registration_ends_at' => now()->addDay(),
        'starts_at' => now()->subDays(2), 'ends_at' => now()->addMonths(3), 'is_active' => true]);

    $student = User::factory()->create(['email' => '300000123@example.com', 'academic_plan_id' => $plan->id]);

    return compact('student', 'plan', 'courses', 'semester', 'past');
}
