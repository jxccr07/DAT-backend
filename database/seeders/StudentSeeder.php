<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    // Default student seeding size. Edit these values here when you need a different dataset size.
    protected int $studentCount = 1000000;

    protected int $batchSize = 5000;

    private const EMAIL_DOMAIN = 'example.com';
    private const FIRST_SEMESTER_STUDENT_LIMIT = 100;
    private const STUDY_YEAR_PATTERN = [2, 2, 3, 3, 3, 4, 4, 4, 4, 5, 5, 6, 7];

    private const FIRST_NAMES = ['محمد', 'أحمد', 'خالد', 'سارة', 'نور', 'لينا', 'عمر', 'ريم', 'يوسف', 'هدى', 'عبدالله', 'مها', 'رغد', 'فيصل', 'نوف', 'تركي', 'أريج', 'حسن', 'فاطمة', 'عبدالرحمن'];

    private const MIDDLE_NAMES = ['أحمد', 'محمد', 'إبراهيم', 'محمود', 'ناصر', 'طارق', 'سالم', 'فهد', 'سمير', 'حسن', 'علي', 'مازن', 'خالد', 'عبدالله', 'صالح', 'ياسر', 'سعيد', 'جابر'];

    private const FAMILY_NAMES = ['العتيبي', 'الشمري', 'القحطاني', 'الزهراني', 'الغامدي', 'الحربي', 'المالكي', 'الجهني', 'السبيعي', 'المطيري', 'العنزي', 'الدوسري', 'البلوي', 'التميمي'];

    public function run(): void
    {
        $studentCount = max(1, $this->studentCount);
        $batchSize = max(500, $this->batchSize);
        $planIds = DB::table('academic_plans')->orderBy('id')->pluck('id')->values()->all();
        $now = now();
        $hashedPassword = Hash::make('password');

        // Seed students in batches so the project can scale to very large datasets.
        for ($start = 1; $start <= $studentCount; $start += $batchSize) {
            $end = min($start + $batchSize - 1, $studentCount);
            $rows = [];

            for ($index = $start; $index <= $end; $index++) {
                $rows[] = $this->studentRow($index, $planIds, $hashedPassword, $now);
            }

            DB::table('users')->insert($rows);
        }
    }

    private function studentRow(int $index, array $planIds, string $hashedPassword, $now): array
    {
        $academicNumber = $this->academicNumberFor($index);

        return [
            'full_name' => $this->randomArabicName(),
            'email' => "{$academicNumber}@".self::EMAIL_DOMAIN,
            'phone_number' => $this->phoneNumberFor($index),
            'email_verified_at' => $now,
            'password' => $hashedPassword,
            'academic_plan_id' => $this->academicPlanIdFor($index, $planIds),
            'remember_token' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function academicPlanIdFor(int $index, array $planIds): int
    {
        if ($index === 1) {
            return $planIds[0];
        }

        return $planIds[array_rand($planIds)];
    }

    private function academicNumberFor(int $index): string
    {
        $studyYear = $this->studyYearFor($index);

        return sprintf('%d%08d', $studyYear, $index);
    }

    private function studyYearFor(int $index): int
    {
        if ($index <= self::FIRST_SEMESTER_STUDENT_LIMIT) {
            return 1;
        }

        $position = ($index - self::FIRST_SEMESTER_STUDENT_LIMIT - 1) % count(self::STUDY_YEAR_PATTERN);

        return self::STUDY_YEAR_PATTERN[$position];
    }

    private function phoneNumberFor(int $index): string
    {
        return '05'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);
    }

    private function randomArabicName(): string
    {
        return implode(' ', [
            self::FIRST_NAMES[array_rand(self::FIRST_NAMES)],
            self::MIDDLE_NAMES[array_rand(self::MIDDLE_NAMES)],
            self::MIDDLE_NAMES[array_rand(self::MIDDLE_NAMES)],
            self::FAMILY_NAMES[array_rand(self::FAMILY_NAMES)],
        ]);
    }
}
