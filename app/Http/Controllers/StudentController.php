<?php

namespace App\Http\Controllers;

use App\Models\StudentAcademicRecord;
use App\Services\CourseRegistrationService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $student = $this->student($request)->load('academicPlan');

        return response()->json([
            'data' => [
                'id' => $student->id,
                'student_id' => $this->studentCode($student),
                'full_name' => $student->full_name,
                'email' => $student->email,
                'phone_number' => $student->phone_number,
                'study_year' => $this->studyYear($student),
                'role' => $student->role ?? 'student',
                'unread_messages' => app(\App\Services\MessagingService::class)->unreadForStudent($student),
                'academic_plan' => $student->academicPlan ? [
                    'id' => $student->academicPlan->id,
                    'name' => $student->academicPlan->name,
                    'program' => $student->academicPlan->program,
                    'college' => $student->academicPlan->college,
                    'major' => $student->academicPlan->major,
                ] : null,
            ],
        ]);
    }

    public function schedule(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->buildSchedulePayload($this->student($request)),
        ]);
    }

    public function academicPlan(Request $request): JsonResponse
    {
        $student = $this->student($request)->load([
            'academicPlan.courses' => fn ($query) => $query
                ->with('specialization')
                ->orderBy('academic_plan_courses.suggested_semester')
                ->orderBy('courses.code'),
            'academicRecords.course',
        ]);

        $academicPlan = $student->academicPlan;

        if (! $academicPlan) {
            return response()->json(['data' => null]);
        }

        $courseStatuses = CourseRegistrationService::courseStatuses($student->academicRecords);

        $semesters = $academicPlan->courses
            ->groupBy(fn ($course) => (int) $course->pivot->suggested_semester)
            ->map(function ($courses, int $semesterNumber) use ($courseStatuses) {
                $courseRows = $courses->values()->map(function ($course, int $index) use ($courseStatuses) {
                    $status = $courseStatuses[$course->id] ?? 'not_studied';

                    return [
                        'index' => $index + 1,
                        'id' => $course->id,
                        'code' => $course->code,
                        'name' => $course->name,
                        'description' => $course->description,
                        'specialization' => $course->specialization ? [
                            'id' => $course->specialization->id,
                            'name' => $course->specialization->name,
                        ] : null,
                        'level' => $course->level,
                        'hours' => $course->hours,
                        'has_lab' => $course->has_lab,
                        'suggested_semester' => $course->pivot->suggested_semester,
                        'status' => $status,
                        'is_passed' => $status === 'passed',
                    ];
                });

                return [
                    'number' => $semesterNumber,
                    'title' => "المستوى {$semesterNumber}",
                    'hours' => $courseRows->sum('hours'),
                    'is_completed' => $courseRows->isNotEmpty() && $courseRows->every(fn (array $course) => $course['is_passed']),
                    'courses' => $courseRows->values(),
                ];
            })
            ->sortKeys()
            ->values();

        $allCourses = $semesters->flatMap(fn (array $semester) => $semester['courses']);

        return response()->json([
            'data' => [
                'plan' => [
                    'id' => $academicPlan->id,
                    'name' => $academicPlan->name,
                    'description' => $academicPlan->description,
                    'program' => $academicPlan->program,
                    'college' => $academicPlan->college,
                    'major' => $academicPlan->major,
                    'is_active' => $academicPlan->is_active,
                ],
                'summary' => [
                    'plan_hours' => $allCourses->sum('hours'),
                    'passed_hours' => $allCourses->where('is_passed', true)->sum('hours'),
                    'plan_courses_count' => $allCourses->count(),
                    'passed_courses_count' => $allCourses->where('is_passed', true)->count(),
                    'is_completed' => $allCourses->isNotEmpty() && $allCourses->every(fn (array $course) => $course['is_passed']),
                ],
                'semesters' => $semesters,
            ],
        ]);
    }

    public function academicTerm(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->buildAcademicTermPayload($this->student($request)),
        ]);
    }

    public function academicGrades(Request $request): JsonResponse
    {
        $student = $this->student($request)->load([
            'academicPlan',
            'academicRecords.course',
            'academicRecords.semester',
        ]);

        return response()->json([
            'data' => $this->buildGradesPayload($student),
        ]);
    }

    private function buildAcademicTermPayload(User $student): array
    {
        $student->loadMissing([
            'academicRecords.course',
            'academicRecords.semester',
        ]);

        $activeSemester = $this->resolveActiveSemester($student);

        $courses = $student->academicRecords
            ->filter(function (StudentAcademicRecord $record) use ($activeSemester) {
                if (! $activeSemester) {
                    return $record->status === 'current_course';
                }

                return $record->semester_id === $activeSemester->id
                    && $record->status === 'current_course';
            })
            ->sortBy('course.code')
            ->values();

        $courses = $courses->map(function (StudentAcademicRecord $record) {
            return [
                'id' => $record->id,
                'status' => $record->status,
                'grade' => $record->grade,
                'course' => $record->course ? [
                    'id' => $record->course->id,
                    'code' => $record->course->code,
                    'name' => $record->course->name,
                    'hours' => $record->course->hours,
                    'has_lab' => $record->course->has_lab,
                ] : null,
            ];
        });

        return [
            'semester' => $activeSemester ? [
                'id' => $activeSemester->id,
                'name' => $activeSemester->name,
                'academic_year' => $activeSemester->academic_year,
                'semester' => $activeSemester->semester,
                'starts_at' => $activeSemester->starts_at,
                'ends_at' => $activeSemester->ends_at,
            ] : null,
            'summary' => [
                'courses_count' => $courses->count(),
                'total_hours' => $courses->sum(fn (array $course) => $course['course']['hours'] ?? 0),
            ],
            'courses' => $courses->values(),
        ];
    }

    private function buildSchedulePayload(User $student): array
    {
        $term = $this->buildAcademicTermPayload($student);
        $currentRecords = $student->academicRecords
            ->filter(function (StudentAcademicRecord $record) use ($term) {
                $activeSemesterId = $term['semester']['id'] ?? null;

                if (! $activeSemesterId) {
                    return $record->status === 'current_course';
                }

                return $record->semester_id === $activeSemesterId
                    && $record->status === 'current_course';
            })
            ->sortBy('course.code')
            ->values();

        $schedule = $this->buildScheduleData($currentRecords);
        $courseSchedules = $schedule['entries']
            ->groupBy('code')
            ->map(fn ($entries) => $entries->values())
            ->all();

        $courses = collect($term['courses'])->map(function (array $courseRow) use ($courseSchedules) {
            $courseCode = $courseRow['course']['code'] ?? null;
            $courseRow['schedule'] = $courseCode ? ($courseSchedules[$courseCode] ?? []) : [];

            return $courseRow;
        })->values();

        return [
            'semester' => $term['semester'],
            'summary' => [
                ...$term['summary'],
                'study_days_count' => $schedule['study_days_count'],
            ],
            'courses' => $courses,
            'schedule_entries' => $schedule['entries']->values(),
            'registered_courses' => $schedule['registered_courses']->values(),
            'schedule_grid' => $schedule['grid']->values(),
        ];
    }

    private function buildScheduleData($currentRecords): array
    {
        $days = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];
        $timeSlots = [
            ['key' => '08:00 - 09:50', 'label' => '08:00 - 09:50'],
            ['key' => '10:00 - 11:50', 'label' => '10:00 - 11:50'],
            ['key' => '12:00 - 13:50', 'label' => '12:00 - 13:50'],
            ['key' => '14:00 - 15:50', 'label' => '14:00 - 15:50'],
        ];

        $grid = collect($timeSlots)->map(function ($timeSlot) use ($days) {
            return [
                'time' => $timeSlot['label'],
                'cells' => collect($days)->mapWithKeys(fn ($day) => [$day => null])->all(),
            ];
        })->values();

        $entries = $currentRecords->values()->map(function (StudentAcademicRecord $record, int $index) use ($days, $timeSlots) {
            $course = $record->course;
            $day = $days[$index % count($days)];
            $slot = $timeSlots[intdiv($index, count($days)) % count($timeSlots)];
            $courseType = $course && $course->has_lab ? 'عملي' : 'نظري';
            $buildingLetter = chr(65 + ($course?->level ?? 1) - 1);
            $roomNumber = 100 + (($course?->id ?? ($index + 1)) % 15) * 7;

            return [
                'record_id' => $record->id,
                'course_id' => $course?->id,
                'code' => $course?->code,
                'name' => $course?->name,
                'hours' => $course?->hours ?? 0,
                'day' => $day,
                'day_index' => $index % count($days),
                'time' => $slot['key'],
                'type' => $courseType,
                'is_lab' => (bool) ($course?->has_lab ?? false),
                'room' => (($course?->has_lab ?? false) ? 'معمل' : 'قاعة')." {$buildingLetter}-{$roomNumber}",
                'room_code' => "{$buildingLetter}-{$roomNumber}",
                'section' => str_pad((string) (($course?->id ?? 0) + 200), 3, '0', STR_PAD_LEFT),
            ];
        })->values();

        foreach ($entries as $entry) {
            $rowIndex = collect($timeSlots)->search(fn ($timeSlot) => $timeSlot['key'] === $entry['time']);

            if ($rowIndex !== false) {
                $row = $grid->get($rowIndex);
                $cells = $row['cells'];
                $cells[$entry['day']] = $entry;
                $grid[$rowIndex] = [
                    'time' => $row['time'],
                    'cells' => $cells,
                ];
            }
        }

        $registeredCourses = $entries
            ->groupBy('code')
            ->map(function ($entries) {
                $first = $entries->first();

                return [
                    'course_id' => $first['course_id'],
                    'code' => $first['code'],
                    'name' => $first['name'],
                    'hours' => $first['hours'],
                    'type' => $entries->pluck('type')->unique()->implode(' / '),
                    'section' => $entries->pluck('section')->unique()->implode(' / '),
                    'days' => $entries->pluck('day')->unique()->values(),
                    'times' => $entries->pluck('time')->unique()->values(),
                    'rooms' => $entries->pluck('room')->unique()->values(),
                    'meetings' => $entries->map(fn ($entry) => [
                        'day' => $entry['day'],
                        'time' => $entry['time'],
                        'room' => $entry['room'],
                        'type' => $entry['type'],
                        'section' => $entry['section'],
                    ])->values(),
                ];
            })
            ->values();

        return [
            'entries' => $entries,
            'registered_courses' => $registeredCourses,
            'grid' => $grid,
            'study_days_count' => $entries->pluck('day')->unique()->count(),
        ];
    }

    private function resolveActiveSemester(User $student): mixed
    {
        return $student->academicRecords
            ->pluck('semester')
            ->filter()
            ->sortByDesc('starts_at')
            ->firstWhere('is_active', true);
    }

    private function buildAcademicPlanSummary(User $student): ?array
    {
        $academicPlan = $student->academicPlan;

        if (! $academicPlan) {
            return null;
        }

        $courseStatuses = CourseRegistrationService::courseStatuses($student->academicRecords);

        $courses = $academicPlan->courses->map(function ($course) use ($courseStatuses) {
            $status = $courseStatuses[$course->id] ?? 'not_studied';

            return [
                'hours' => $course->hours,
                'is_passed' => $status === 'passed',
            ];
        });

        return [
            'plan' => [
                'id' => $academicPlan->id,
                'name' => $academicPlan->name,
                'description' => $academicPlan->description,
                'program' => $academicPlan->program,
                'college' => $academicPlan->college,
                'major' => $academicPlan->major,
                'is_active' => $academicPlan->is_active,
            ],
            'summary' => [
                'plan_hours' => $courses->sum('hours'),
                'passed_hours' => $courses->where('is_passed', true)->sum('hours'),
                'plan_courses_count' => $courses->count(),
                'passed_courses_count' => $courses->where('is_passed', true)->count(),
                'is_completed' => $courses->isNotEmpty() && $courses->every(fn (array $course) => $course['is_passed']),
            ],
        ];
    }

    private function buildGradesPayload(User $student): array
    {
        $gradedRecords = $student->academicRecords
            ->filter(fn ($record) => in_array($record->status, ['passed', 'failed'], true) && $record->grade !== null)
            ->sortBy(function ($record) {
                return [
                    optional($record->semester?->starts_at)->timestamp ?? 0,
                    $record->course?->code ?? '',
                ];
            })
            ->values();

        $terms = $gradedRecords
            ->groupBy('semester_id')
            ->map(function ($records) {
                $semester = $records->first()?->semester;

                $courses = $records->map(function ($record) {
                    $grade = round((float) $record->grade, 2);
                    $scale = $this->gradeScale($grade);

                    return [
                        'id' => $record->id,
                        'status' => $record->status,
                        'grade' => $grade,
                        'letter' => $scale['letter'],
                        'points' => $scale['points'],
                        'quality_points' => $scale['points'] * ($record->course?->hours ?? 0),
                        'course' => $record->course ? [
                            'id' => $record->course->id,
                            'code' => $record->course->code,
                            'name' => $record->course->name,
                            'hours' => $record->course->hours,
                        ] : null,
                    ];
                })->values();

                $hours = $courses->sum(fn (array $course) => $course['course']['hours'] ?? 0);
                $qualityPoints = $courses->sum('quality_points');

                return [
                    'semester' => $semester ? [
                        'id' => $semester->id,
                        'name' => $semester->name,
                        'academic_year' => $semester->academic_year,
                        'semester' => $semester->semester,
                        'starts_at' => $semester->starts_at,
                        'ends_at' => $semester->ends_at,
                    ] : null,
                    'semester_gpa' => $hours > 0 ? round($qualityPoints / $hours, 2) : null,
                    'hours' => $hours,
                    'courses' => $courses,
                    'sort_value' => optional($semester?->starts_at)->timestamp ?? 0,
                ];
            })
            ->sortBy('sort_value')
            ->values()
            ->map(function (array $term) {
                unset($term['sort_value']);

                return $term;
            });

        $passedCourses = $gradedRecords->where('status', 'passed');
        $passedHours = $passedCourses->sum(fn ($record) => $record->course?->hours ?? 0);
        $totalQualityPoints = $gradedRecords->sum(function ($record) {
            $scale = $this->gradeScale((float) $record->grade);

            return $scale['points'] * ($record->course?->hours ?? 0);
        });
        $totalHours = $gradedRecords->sum(fn ($record) => $record->course?->hours ?? 0);
        $activeSemester = $this->resolveActiveSemester($student);
        $currentTerm = $terms->first(function (array $term) use ($activeSemester) {
            return ($term['semester']['id'] ?? null) === $activeSemester?->id;
        }) ?? $terms->last();

        return [
            'summary' => [
                'cumulative_gpa' => $totalHours > 0 ? round($totalQualityPoints / $totalHours, 2) : null,
                'current_gpa' => $currentTerm['semester_gpa'] ?? null,
                'passed_hours' => $passedHours,
                'courses_count' => $gradedRecords->count(),
            ],
            'terms' => $terms,
        ];
    }

    private function gradeScale(float $grade): array
    {
        return match (true) {
            $grade >= 95 => ['letter' => 'A+', 'points' => 4.00],
            $grade >= 90 => ['letter' => 'A', 'points' => 3.75],
            $grade >= 85 => ['letter' => 'B+', 'points' => 3.50],
            $grade >= 80 => ['letter' => 'B', 'points' => 3.00],
            $grade >= 75 => ['letter' => 'C+', 'points' => 2.50],
            $grade >= 70 => ['letter' => 'C', 'points' => 2.00],
            $grade >= 65 => ['letter' => 'D+', 'points' => 1.50],
            $grade >= 60 => ['letter' => 'D', 'points' => 1.00],
            default => ['letter' => 'F', 'points' => 0.00],
        };
    }

    private function student(Request $request): User
    {
        return $request->user();
    }

    /**
     * Academic numbers are generated as {studyYear}{8-digit sequence}.
     */
    private function studyYear(User $student): ?int
    {
        $code = $this->studentCode($student);

        return strlen($code) === 9 && ctype_digit($code) ? (int) $code[0] : null;
    }

    private function studentCode(User $student): string
    {
        return explode('@', $student->email)[0];
    }
}
