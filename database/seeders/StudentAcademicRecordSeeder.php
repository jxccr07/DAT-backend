<?php

namespace Database\Seeders;

use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentAcademicRecordSeeder extends Seeder
{
    // Default chunk sizes for academic record inserts. Edit these values here if needed.
    protected int $studentChunkSize = 5000;

    protected int $insertChunkSize = 2000;

    private const CURRENT_STATUS = 'current_course';
    private const PASSED_STATUS = 'passed';
    private const FAILED_STATUS = 'failed';
    private const DELETED_STATUS = 'deleted_course';
    private const MIN_INSERT_CHUNK_SIZE = 100;
    private const SAFE_PLACEHOLDER_LIMIT = 20000;
    private const PERFORMANCE_STRUGGLING = 'struggling';
    private const PERFORMANCE_AVERAGE = 'average';
    private const PERFORMANCE_STRONG = 'strong';
    private const PERFORMANCE_EXCELLENT = 'excellent';

    public function run(): void
    {
        $studentChunkSize = max(500, $this->studentChunkSize);
        $insertChunkSize = max(1000, $this->insertChunkSize);
        $semesters = DB::table('semesters')->orderBy('starts_at')->get();

        $semesterIds = $semesters->pluck('id')->values();

        $planCourses = DB::table('academic_plan_courses')
            ->join('courses', 'courses.id', '=', 'academic_plan_courses.course_id')
            ->select(
                'academic_plan_courses.academic_plan_id',
                'academic_plan_courses.suggested_semester',
                'academic_plan_courses.course_id'
            )
            ->orderBy('academic_plan_courses.academic_plan_id')
            ->orderBy('academic_plan_courses.suggested_semester')
            ->orderBy('courses.code')
            ->get()
            ->groupBy('academic_plan_id');

        // Seed academic history from each student's own plan instead of using one shared course set.
        DB::table('users')
            ->select('id', 'academic_plan_id', 'email')
            ->orderBy('id')
            ->chunkById($studentChunkSize, function (Collection $students) use (
                $planCourses,
                $semesterIds,
                $insertChunkSize
            ): void {
                $rows = $this->buildChunkRows($students, $planCourses, $semesterIds);
                $this->flushRows($rows, $insertChunkSize);
            });
    }

    private function buildChunkRows(Collection $students, Collection $planCourses, Collection $semesterIds): array
    {
        $rows = [];
        $now = now();

        foreach ($students as $student) {
            $coursesBySemester = $this->studentCoursesBySemester($student->academic_plan_id, $planCourses);
            $studyYear = (int) substr(explode('@', $student->email)[0], 0, 1);
            $performanceBand = $this->performanceBandForStudent($student->id);

            $completedSemesters = match ($studyYear) {
                1 => [],
                2 => [1, 2],
                3 => [1, 2, 3, 4],
                4 => [1, 2, 3, 4, 5, 6],
                5 => [1, 2, 3, 4, 5],
                6 => [1, 2, 3, 4, 5, 6],
                default => [1, 2, 3, 4, 5, 6, 7, 8],
            };

            $delayedSemesters = match ($studyYear) {
                5 => [6, 7],
                6 => [7],
                default => [],
            };

            $currentSemesters = match ($studyYear) {
                1 => [1],
                2 => [3],
                3 => [5],
                4 => [7],
                5 => [8],
                6 => [8],
                default => [],
            };

            foreach ($completedSemesters as $planSemester) {
                $semesterId = $semesterIds->get($planSemester - 1);

                if (! $semesterId) {
                    continue;
                }

                foreach (($coursesBySemester->get($planSemester, collect())) as $course) {
                    $record = $this->historicalRecord($performanceBand, false);
                    $rows[] = $this->recordRow(
                        $student->id,
                        $course->course_id,
                        $semesterId,
                        $record['status'],
                        $record['grade'],
                        $now
                    );
                }
            }

            foreach ($delayedSemesters as $planSemester) {
                $semesterId = $semesterIds->get($planSemester - 1);

                if (! $semesterId) {
                    continue;
                }

                foreach (($coursesBySemester->get($planSemester, collect())) as $course) {
                    $record = $this->historicalRecord($performanceBand, true);
                    $rows[] = $this->recordRow(
                        $student->id,
                        $course->course_id,
                        $semesterId,
                        $record['status'],
                        $record['grade'],
                        $now
                    );
                }
            }

            foreach ($currentSemesters as $planSemester) {
                $currentSemesterId = $semesterIds->last();

                if (! $currentSemesterId) {
                    continue;
                }

                foreach (($coursesBySemester->get($planSemester, collect()))->take(3) as $course) {
                    $rows[] = $this->recordRow(
                        $student->id,
                        $course->course_id,
                        $currentSemesterId,
                        self::CURRENT_STATUS,
                        null,
                        $now
                    );
                }
            }
        }

        return $rows;
    }

    private function studentCoursesBySemester(int $academicPlanId, Collection $planCourses): Collection
    {
        return collect($planCourses->get($academicPlanId, collect()))->groupBy('suggested_semester');
    }

    private function recordRow(int $studentId, int $courseId, int $semesterId, string $status, ?int $grade, $now): array
    {
        return [
            'student_id' => $studentId,
            'course_id' => $courseId,
            'semester_id' => $semesterId,
            'status' => $status,
            'grade' => $grade,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function flushRows(array &$rows, int $insertChunkSize): void
    {
        if ($rows === []) {
            return;
        }

        $safeChunkSize = $this->safeChunkSize($rows[0], $insertChunkSize);

        foreach (array_chunk($rows, $safeChunkSize) as $chunk) {
            $this->insertChunkWithRetry($chunk);
        }

        $rows = [];
    }

    private function safeChunkSize(array $row, int $requestedChunkSize): int
    {
        $columnCount = max(1, count($row));
        $placeholderBasedLimit = intdiv(self::SAFE_PLACEHOLDER_LIMIT, $columnCount);

        return max(
            self::MIN_INSERT_CHUNK_SIZE,
            min($requestedChunkSize, $placeholderBasedLimit)
        );
    }

    private function insertChunkWithRetry(array $chunk): void
    {
        try {
            DB::table('student_academic_records')->insert($chunk);
        } catch (QueryException $exception) {
            if (! $this->isTooManyPlaceholdersError($exception) || count($chunk) <= 1) {
                throw $exception;
            }

            $half = max(1, intdiv(count($chunk), 2));

            $this->insertChunkWithRetry(array_slice($chunk, 0, $half));
            $this->insertChunkWithRetry(array_slice($chunk, $half));
        }
    }

    private function isTooManyPlaceholdersError(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), '1390')
            || str_contains($exception->getMessage(), 'too many placeholders');
    }

    private function performanceBandForStudent(int $studentId): string
    {
        $roll = $this->deterministicRoll($studentId, 'performance');

        return match (true) {
            $roll <= 18 => self::PERFORMANCE_STRUGGLING,
            $roll <= 68 => self::PERFORMANCE_AVERAGE,
            $roll <= 90 => self::PERFORMANCE_STRONG,
            default => self::PERFORMANCE_EXCELLENT,
        };
    }

    private function historicalRecord(string $performanceBand, bool $isDelayedSemester): array
    {
        $roll = mt_rand(1, 100);
        $distribution = $this->statusDistributionFor($performanceBand, $isDelayedSemester);
        $passThreshold = $distribution['pass'];
        $failThreshold = $distribution['pass'] + $distribution['fail'];

        if ($roll <= $passThreshold) {
            return [
                'status' => self::PASSED_STATUS,
                'grade' => $this->passedGradeFor($performanceBand),
            ];
        }

        if ($roll <= $failThreshold) {
            return [
                'status' => self::FAILED_STATUS,
                'grade' => $this->failedGradeFor($performanceBand),
            ];
        }

        return [
            'status' => self::DELETED_STATUS,
            'grade' => null,
        ];
    }

    private function statusDistributionFor(string $performanceBand, bool $isDelayedSemester): array
    {
        $distribution = match ($performanceBand) {
            self::PERFORMANCE_STRUGGLING => ['pass' => 58, 'fail' => 30, 'delete' => 12],
            self::PERFORMANCE_AVERAGE => ['pass' => 80, 'fail' => 14, 'delete' => 6],
            self::PERFORMANCE_STRONG => ['pass' => 92, 'fail' => 5, 'delete' => 3],
            default => ['pass' => 97, 'fail' => 2, 'delete' => 1],
        };

        if (! $isDelayedSemester) {
            return $distribution;
        }

        return match ($performanceBand) {
            self::PERFORMANCE_STRUGGLING => ['pass' => 42, 'fail' => 38, 'delete' => 20],
            self::PERFORMANCE_AVERAGE => ['pass' => 68, 'fail' => 20, 'delete' => 12],
            self::PERFORMANCE_STRONG => ['pass' => 84, 'fail' => 9, 'delete' => 7],
            default => ['pass' => 92, 'fail' => 4, 'delete' => 4],
        };
    }

    private function passedGradeFor(string $performanceBand): int
    {
        return match ($performanceBand) {
            self::PERFORMANCE_STRUGGLING => $this->weightedGrade([
                ['chance' => 65, 'min' => 60, 'max' => 69],
                ['chance' => 30, 'min' => 70, 'max' => 76],
                ['chance' => 5, 'min' => 77, 'max' => 82],
            ]),
            self::PERFORMANCE_AVERAGE => $this->weightedGrade([
                ['chance' => 20, 'min' => 60, 'max' => 69],
                ['chance' => 55, 'min' => 70, 'max' => 82],
                ['chance' => 25, 'min' => 83, 'max' => 91],
            ]),
            self::PERFORMANCE_STRONG => $this->weightedGrade([
                ['chance' => 10, 'min' => 70, 'max' => 79],
                ['chance' => 50, 'min' => 80, 'max' => 89],
                ['chance' => 40, 'min' => 90, 'max' => 96],
            ]),
            default => $this->weightedGrade([
                ['chance' => 10, 'min' => 84, 'max' => 89],
                ['chance' => 45, 'min' => 90, 'max' => 95],
                ['chance' => 45, 'min' => 96, 'max' => 99],
            ]),
        };
    }

    private function failedGradeFor(string $performanceBand): int
    {
        return match ($performanceBand) {
            self::PERFORMANCE_STRUGGLING => mt_rand(42, 58),
            self::PERFORMANCE_AVERAGE => mt_rand(48, 59),
            self::PERFORMANCE_STRONG => mt_rand(54, 59),
            default => mt_rand(57, 59),
        };
    }

    private function weightedGrade(array $ranges): int
    {
        $roll = mt_rand(1, 100);
        $threshold = 0;

        foreach ($ranges as $range) {
            $threshold += $range['chance'];

            if ($roll <= $threshold) {
                return mt_rand($range['min'], $range['max']);
            }
        }

        $fallback = $ranges[array_key_last($ranges)];

        return mt_rand($fallback['min'], $fallback['max']);
    }

    private function deterministicRoll(int $studentId, string $salt): int
    {
        return ((int) sprintf('%u', crc32($studentId.'-'.$salt)) % 100) + 1;
    }
}
