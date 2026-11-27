<?php

namespace Database\Seeders;

use App\Models\AcademicPlan;
use App\Models\Course;
use Database\Seeders\Support\UniversityCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademicPlanSeeder extends Seeder
{
    public function run(): void
    {
        $coursesByCode = Course::query()->pluck('id', 'code');
        $now = now();

        foreach (UniversityCatalog::plans() as $planData) {
            // Seed one academic plan per major, then attach its required courses.
            $plan = AcademicPlan::create([
                'name' => $planData['name'],
                'description' => $this->buildDescription($planData['major'], $planData['college']),
                'program' => $planData['program'],
                'college' => $planData['college'],
                'major' => $planData['major'],
                'is_active' => true,
            ]);

            $rows = [];

            foreach ($planData['courses'] as $courseData) {
                $rows[] = [
                    'academic_plan_id' => $plan->id,
                    'course_id' => $coursesByCode[$courseData['code']],
                    'suggested_semester' => $courseData['suggested_semester'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('academic_plan_courses')->insert($rows);
        }
    }

    private function buildDescription(string $major, string $college): string
    {
        return fake()->randomElement([
            "خطة أكاديمية منظمة لطلاب {$major} في {$college}.",
            "خطة دراسية مصممة لتغطية مقررات {$major} عبر فصول متدرجة.",
            "خطة جامعية مبسطة تدعم تحليل الأداء الأكاديمي لتخصص {$major}.",
        ]);
    }
}
