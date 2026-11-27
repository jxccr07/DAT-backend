<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Specialize;
use Database\Seeders\Support\UniversityCatalog;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    public function run(): void
    {
        $specializationIds = Specialize::query()
            ->pluck('id', 'name')
            ->all();

        // Seed the full course catalog used across all colleges and majors.
        foreach (UniversityCatalog::allCourses() as $courseData) {
            $specializationName = $courseData['specializes_in'];

            if (! isset($specializationIds[$specializationName])) {
                throw new \RuntimeException("Missing specialization [{$specializationName}] for course [{$courseData['code']}]");
            }

            Course::create([
                'name' => $courseData['name'],
                'code' => $courseData['code'],
                'description' => $this->buildDescription($courseData['name'], $specializationName, $courseData['has_lab']),
                'specialization_id' => $specializationIds[$specializationName],
                'level' => $courseData['level'],
                'hours' => $courseData['hours'],
                'has_lab' => $courseData['has_lab'],
                'is_active' => true,
            ]);
        }
    }

    private function buildDescription(string $courseName, string $specialization, bool $hasLab): string
    {
        $prefix = fake()->randomElement([
            'يركز هذا المقرر على',
            'يقدم هذا المقرر أساسيات',
            'يعالج هذا المقرر تطبيقات',
            'يبني هذا المقرر فهما متدرجا في',
        ]);

        $suffix = $hasLab
            ? fake()->randomElement([
                'ويتضمن مختبرا عمليا وتمارين تطبيقية.',
                'ويتبع ذلك جانب عملي يدعم المهارات المهنية.',
                'مع تدريبات عملية تساعد على ربط المفهوم بالتنفيذ.',
            ])
            : fake()->randomElement([
                'مع أمثلة أكاديمية تدعم فهم المادة.',
                'بأسلوب مناسب للمستوى الجامعي والتحليل العلمي.',
                'مع تركيز على الفهم النظري وتفسير الحالات الدراسية.',
            ]);

        return "{$prefix} {$courseName} ضمن مسار {$specialization}، {$suffix}";
    }
}
