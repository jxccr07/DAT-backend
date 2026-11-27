<?php

namespace Database\Seeders;

use App\Models\Semester;
use Illuminate\Database\Seeder;

class SemesterSeeder extends Seeder
{
    public function run(): void
    {
        $semesters = [
            [
                'name' => 'الفصل الدراسي الأول 2023/2024',
                'academic_year' => '2023/2024',
                'semester' => 'first',
                'registration_starts_at' => '2023-08-10 08:00:00',
                'registration_ends_at' => '2023-08-24 23:59:59',
                'starts_at' => '2023-08-27 08:00:00',
                'ends_at' => '2024-01-04 23:59:59',
                'is_active' => false,
            ],
            [
                'name' => 'الفصل الدراسي الثاني 2023/2024',
                'academic_year' => '2023/2024',
                'semester' => 'second',
                'registration_starts_at' => '2024-01-07 08:00:00',
                'registration_ends_at' => '2024-01-18 23:59:59',
                'starts_at' => '2024-01-21 08:00:00',
                'ends_at' => '2024-05-16 23:59:59',
                'is_active' => false,
            ],
            [
                'name' => 'الفصل الدراسي الأول 2024/2025',
                'academic_year' => '2024/2025',
                'semester' => 'first',
                'registration_starts_at' => '2024-08-11 08:00:00',
                'registration_ends_at' => '2024-08-22 23:59:59',
                'starts_at' => '2024-08-25 08:00:00',
                'ends_at' => '2025-01-02 23:59:59',
                'is_active' => false,
            ],
            [
                'name' => 'الفصل الدراسي الثاني 2024/2025',
                'academic_year' => '2024/2025',
                'semester' => 'second',
                'registration_starts_at' => '2025-01-05 08:00:00',
                'registration_ends_at' => '2025-01-16 23:59:59',
                'starts_at' => '2025-01-19 08:00:00',
                'ends_at' => '2025-05-15 23:59:59',
                'is_active' => false,
            ],
            [
                'name' => 'الفصل الدراسي الأول 2025/2026',
                'academic_year' => '2025/2026',
                'semester' => 'first',
                'registration_starts_at' => '2025-08-10 08:00:00',
                'registration_ends_at' => '2025-08-24 23:59:59',
                'starts_at' => '2025-08-31 08:00:00',
                'ends_at' => '2026-01-10 23:59:59',
                'is_active' => false,
            ],
            [
                'name' => 'الفصل الدراسي الثاني 2025/2026',
                'academic_year' => '2025/2026',
                'semester' => 'second',
                'registration_starts_at' => '2026-01-10 08:00:00',
                'registration_ends_at' => '2026-01-22 23:59:59',
                'starts_at' => '2026-01-25 08:00:00',
                'ends_at' => '2026-05-20 23:59:59',
                'is_active' => false,
            ],
            [
                'name' => 'الفصل الدراسي الأول 2026/2027',
                'academic_year' => '2026/2027',
                'semester' => 'first',
                'registration_starts_at' => '2026-08-10 08:00:00',
                'registration_ends_at' => '2026-08-24 23:59:59',
                'starts_at' => '2026-08-30 08:00:00',
                'ends_at' => '2026-12-24 23:59:59',
                'is_active' => false,
            ],
            [
                'name' => 'الفصل الدراسي الثاني 2026/2027',
                'academic_year' => '2026/2027',
                'semester' => 'second',
                'registration_starts_at' => '2027-01-09 08:00:00',
                'registration_ends_at' => '2027-01-21 23:59:59',
                'starts_at' => '2027-01-24 08:00:00',
                'ends_at' => '2027-05-20 23:59:59',
                'is_active' => true,
            ],
        ];

        // Seed a small semester timeline for previous and current student records.
        foreach ($semesters as $semesterData) {
            Semester::create([
                ...$semesterData,
                'description' => $this->randomDescription($semesterData['name'], $semesterData['is_active']),
            ]);
        }
    }

    private function randomDescription(string $semesterName, bool $isActive): string
    {
        if ($isActive) {
            return fake()->randomElement([
                "{$semesterName} هو الفصل الحالي لعرض مواد الطالب الجارية.",
                "{$semesterName} يستخدم لتمثيل تسجيل الطالب الحالي داخل النظام.",
                "{$semesterName} يمثل الفصل النشط الذي تظهر فيه المواد الحالية.",
            ]);
        }

        return fake()->randomElement([
            "{$semesterName} فصل أكاديمي سابق لعرض السجل الدراسي.",
            "{$semesterName} يستخدم لتخزين نتائج الفصول السابقة.",
            "{$semesterName} جزء من بيانات الأداء الأكاديمي السابقة.",
        ]);
    }
}
