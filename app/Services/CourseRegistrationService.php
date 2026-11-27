<?php

namespace App\Services;

use App\Exceptions\RegistrationException;
use App\Models\Course;
use App\Models\Semester;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Support\DemoRegistrations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CourseRegistrationService
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_CURRENT = 'current_course';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETED = 'deleted_course';

    /**
     * Resolve one status per course from all of a student's records.
     * Priority: passed > current > failed > deleted.
     *
     * @return array<int, string> course_id => status
     */
    public static function courseStatuses(Collection $records): array
    {
        return $records
            ->groupBy('course_id')
            ->map(function (Collection $records) {
                foreach ([self::STATUS_PASSED, self::STATUS_CURRENT, self::STATUS_FAILED, self::STATUS_DELETED] as $status) {
                    if ($records->contains('status', $status)) {
                        return $status;
                    }
                }

                return 'not_studied';
            })
            ->all();
    }

    public function activeSemester(): ?Semester
    {
        return Semester::query()
            ->where('is_active', true)
            ->orderByDesc('starts_at')
            ->first();
    }

    public function isWindowOpen(Semester $semester): bool
    {
        if (! config('academic.registration.enforce_window')) {
            return true;
        }

        if (! $semester->registration_starts_at || ! $semester->registration_ends_at) {
            return false;
        }

        return now()->between($semester->registration_starts_at, $semester->registration_ends_at);
    }

    public function overview(User $student): array
    {
        $semester = $this->activeSemester();

        $student->loadMissing([
            'academicPlan.courses' => fn ($query) => $query
                ->where('courses.is_active', true)
                ->orderBy('academic_plan_courses.suggested_semester')
                ->orderBy('courses.code'),
            'academicRecords.course',
        ]);

        $registered = $this->registeredRecords($student, $semester);
        $registeredIds = $registered->pluck('course_id')->all();
        $statuses = self::courseStatuses($student->academicRecords);

        $planCourses = $student->academicPlan?->courses ?? collect();

        $remaining = $planCourses->filter(
            fn (Course $course) => ($statuses[$course->id] ?? null) !== self::STATUS_PASSED
                && ! in_array($course->id, $registeredIds, true)
        );

        // Recommend the earliest plan levels the student hasn't completed yet.
        $firstOpenLevel = $remaining->min(fn (Course $course) => (int) $course->pivot->suggested_semester);

        $available = $remaining->map(fn (Course $course) => [
            'id' => $course->id,
            'code' => $course->code,
            'name' => $course->name,
            'hours' => $course->hours,
            'has_lab' => $course->has_lab,
            'level' => (int) $course->pivot->suggested_semester,
            'is_retake' => ($statuses[$course->id] ?? null) === self::STATUS_FAILED,
            'is_recommended' => $firstOpenLevel !== null
                && (int) $course->pivot->suggested_semester <= $firstOpenLevel + 1,
        ])->values();

        $registeredHours = $registered->sum(fn (StudentAcademicRecord $record) => $record->course?->hours ?? 0);

        return [
            'semester' => $semester ? [
                'id' => $semester->id,
                'name' => $semester->name,
                'academic_year' => $semester->academic_year,
                'semester' => $semester->semester,
                'registration_starts_at' => $semester->registration_starts_at,
                'registration_ends_at' => $semester->registration_ends_at,
            ] : null,
            'window_open' => $semester ? $this->isWindowOpen($semester) : false,
            'limits' => [
                'max_hours' => (int) config('academic.registration.max_hours'),
                'min_hours' => (int) config('academic.registration.min_hours'),
            ],
            'registered_hours' => $registeredHours,
            'registered' => $registered->map(fn (StudentAcademicRecord $record) => [
                'record_id' => $record->id,
                'id' => $record->course?->id,
                'code' => $record->course?->code,
                'name' => $record->course?->name,
                'hours' => $record->course?->hours ?? 0,
                'has_lab' => $record->course?->has_lab ?? false,
            ])->values(),
            'available' => $available,
        ];
    }

    public function register(User $student, int $courseId): StudentAcademicRecord
    {
        $semester = $this->requireOpenSemester();
        DemoRegistrations::touch($student);

        return DB::transaction(function () use ($student, $courseId, $semester) {
            $course = $student->academicPlan?->courses()
                ->where('courses.id', $courseId)
                ->first();

            if (! $course) {
                throw new RegistrationException('course_not_in_plan');
            }

            if (! $course->is_active) {
                throw new RegistrationException('course_inactive');
            }

            $records = $student->academicRecords()->get();

            if ($records->where('course_id', $course->id)->contains('status', self::STATUS_PASSED)) {
                throw new RegistrationException('course_already_passed');
            }

            $current = $records->where('semester_id', $semester->id)->where('status', self::STATUS_CURRENT);

            if ($current->contains('course_id', $course->id)) {
                throw new RegistrationException('course_already_registered');
            }

            $currentHours = Course::query()->whereIn('id', $current->pluck('course_id'))->sum('hours');

            if ($currentHours + $course->hours > (int) config('academic.registration.max_hours')) {
                throw new RegistrationException('max_hours_exceeded');
            }

            // A previously dropped/failed attempt in this same semester is reused (unique student+course+semester).
            $record = StudentAcademicRecord::query()->firstOrNew([
                'student_id' => $student->id,
                'course_id' => $course->id,
                'semester_id' => $semester->id,
            ]);

            $record->fill(['status' => self::STATUS_CURRENT, 'grade' => null])->save();

            return $record->load('course');
        });
    }

    public function drop(User $student, int $courseId): void
    {
        $semester = $this->requireOpenSemester();
        DemoRegistrations::touch($student);

        $deleted = StudentAcademicRecord::query()
            ->where('student_id', $student->id)
            ->where('course_id', $courseId)
            ->where('semester_id', $semester->id)
            ->where('status', self::STATUS_CURRENT)
            ->delete();

        if ($deleted === 0) {
            throw new RegistrationException('course_not_registered', 404);
        }
    }

    private function requireOpenSemester(): Semester
    {
        $semester = $this->activeSemester();

        if (! $semester) {
            throw new RegistrationException('no_active_semester');
        }

        if (! $this->isWindowOpen($semester)) {
            throw new RegistrationException('registration_closed', 403);
        }

        return $semester;
    }

    private function registeredRecords(User $student, ?Semester $semester): Collection
    {
        if (! $semester) {
            return collect();
        }

        return $student->academicRecords
            ->where('semester_id', $semester->id)
            ->where('status', self::STATUS_CURRENT)
            ->sortBy('course.code')
            ->values();
    }
}
