<?php

namespace App\Twin;

use App\Models\Course;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Services\AdvisingService;
use App\Services\CourseRegistrationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Places a live student in their "students like you" cohort and answers
 * questions against the pre-computed twin statistics (see twin:build).
 */
class TwinService
{
    public function __construct(private readonly CourseRegistrationService $registration)
    {
    }

    public function isReady(): bool
    {
        return DB::table('twin_meta')->where('key', 'summary')->exists();
    }

    public function meta(): ?array
    {
        $value = DB::table('twin_meta')->where('key', 'summary')->value('value');

        return $value ? json_decode($value, true) : null;
    }

    /**
     * Where the student stands right now, described the same way the history was grouped.
     */
    public function profile(User $student): array
    {
        $student->loadMissing(['academicRecords.course', 'academicPlan.courses']);
        $active = $this->registration->activeSemester();

        $history = $student->academicRecords
            ->filter(fn (StudentAcademicRecord $r) => $r->semester_id !== $active?->id)
            ->groupBy('semester_id')
            ->sortKeys();

        $passed = 0;
        $qualityPoints = 0.0;
        $gradedHours = 0;
        foreach ($history as $records) {
            foreach ($records as $record) {
                $hours = (int) ($record->course?->hours ?? 0);
                if ($record->status === CourseRegistrationService::STATUS_PASSED) {
                    $passed += $hours;
                }
                if (in_array($record->status, [CourseRegistrationService::STATUS_PASSED, CourseRegistrationService::STATUS_FAILED], true) && $record->grade !== null) {
                    $gradedHours += $hours;
                    $qualityPoints += $hours * TwinModel::points((float) $record->grade);
                }
            }
        }

        $termsDone = $history->count();
        $plan = $student->academicPlan?->courses ?? collect();
        $expected = (int) $plan->filter(fn (Course $c) => (int) $c->pivot->suggested_semester <= $termsDone)->sum('hours');
        $planHours = (int) $plan->sum('hours');
        $planTerms = (int) ($plan->max(fn (Course $c) => (int) $c->pivot->suggested_semester) ?? 0);
        $gap = max(0, $expected - $passed);
        $gpa = $gradedHours > 0 ? $qualityPoints / $gradedHours : null;

        $statuses = CourseRegistrationService::courseStatuses($student->academicRecords);
        $current = $active
            ? $student->academicRecords->where('semester_id', $active->id)->where('status', CourseRegistrationService::STATUS_CURRENT)
            : collect();

        $remainingHours = max(0, $planHours - $passed);
        $termsLeftInPlan = max(0, $planTerms - $termsDone);

        return [
            'term_no' => $termsDone + 1,
            'phase' => TwinModel::phase($termsDone + 1),
            'gpa' => $gpa !== null ? round($gpa, 2) : null,
            'gpa_band' => TwinModel::gpaBand($gpa),
            'gpa_range' => TwinModel::bandRange(TwinModel::gpaBand($gpa)),
            'lane' => TwinModel::lane($gap),
            'gap_hours' => $gap,
            'passed_hours' => $passed,
            'expected_hours' => $expected,
            'plan_hours' => $planHours,
            'remaining_hours' => $remainingHours,
            'plan_terms_left' => $termsLeftInPlan,
            'registered_hours' => (int) $current->sum(fn ($r) => $r->course?->hours ?? 0),
            'registered_course_ids' => $current->pluck('course_id')->values()->all(),
            'failed_pending' => collect($statuses)->filter(fn ($s) => $s === CourseRegistrationService::STATUS_FAILED)->keys()->values()->all(),
        ];
    }

    /**
     * The one comparison the dialog leads with: {source, n_with, n_without, delayed_with, delayed_without}.
     * "course" = students in the same GPA band who dropped vs kept this course; "general" = any drop, same stage/GPA/progress.
     */
    /**
     * 95% Wilson interval for a share observed in n students: how sure we can be of a rate from a sample this size.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function wilson(float $p, int $n): ?array
    {
        if ($n <= 0) {
            return null;
        }
        $z = 1.96;
        $denominator = 1 + $z ** 2 / $n;
        $centre = ($p + $z ** 2 / (2 * $n)) / $denominator;
        $margin = $z * sqrt($p * (1 - $p) / $n + $z ** 2 / (4 * $n ** 2)) / $denominator;

        return [round(max(0.0, $centre - $margin), 3), round(min(1.0, $centre + $margin), 3)];
    }

    public static function headline(string $action, ?array $course, ?array $comparison): ?array
    {
        $min = (int) config('twin.min_cohort');
        if ($action === 'drop' && $course && $course['delayed_if_dropped'] !== null && $course['delayed_if_kept'] !== null
            && $course['n_dropped'] >= $min && $course['n_kept'] >= $min) {
            return [
                'source' => 'course',
                'n_with' => $course['n_dropped'],
                'n_without' => $course['n_kept'],
                'delayed_with' => $course['delayed_if_dropped'],
                'delayed_without' => $course['delayed_if_kept'],
                'range_with' => self::wilson((float) $course['delayed_if_dropped'], (int) $course['n_dropped']),
                'range_without' => self::wilson((float) $course['delayed_if_kept'], (int) $course['n_kept']),
            ];
        }

        return $comparison ? [
            'source' => 'general',
            'n_with' => $comparison['n_with'],
            'n_without' => $comparison['n_without'],
            'delayed_with' => $comparison['delayed_with'],
            'delayed_without' => $comparison['delayed_without'],
            'range_with' => self::wilson((float) $comparison['delayed_with'], (int) $comparison['n_with']),
            'range_without' => self::wilson((float) $comparison['delayed_without'], (int) $comparison['n_without']),
        ] : null;
    }

    /** GPA under 2.00 or several failed courses still open: recovery before load. */
    public static function isStruggling(array $p): bool
    {
        return $p['gpa_band'] === 'g1' || count($p['failed_pending']) >= 3;
    }

    /** Keep the advisor's ranking current after a student changes their registration. */
    public function refreshStatus(User $student): void
    {
        if (! $this->isReady()) {
            return;
        }
        $student->unsetRelation('academicRecords');
        $p = $this->profile($student);

        DB::table('twin_student_status')->updateOrInsert(['student_id' => $student->id], [
            'term_no' => $p['term_no'],
            'gpa' => $p['gpa'],
            'gpa_band' => $p['gpa_band'],
            'lane' => $p['lane'],
            'gap_hours' => $p['gap_hours'],
            'failed_pending' => count($p['failed_pending']),
            'registered_hours' => $p['registered_hours'],
            'risk' => $this->cohort($p)['delayed'] ?? null,
            'updated_at' => now(),
        ]);
    }

    /** Status card: where you are, what students like you went on to, why, and one next step. */
    public function status(User $student): array
    {
        $p = $this->profile($student);
        $cohort = $this->cohort($p);
        $min = (int) config('academic.registration.min_hours');
        $avgLoad = 15;

        $reasons = [];
        if ($p['gap_hours'] > 3) {
            $reasons[] = ['code' => 'behind_plan', 'hours' => $p['gap_hours']];
        }
        if ($n = count($p['failed_pending'])) {
            $reasons[] = ['code' => 'failed_pending', 'count' => $n];
        }
        if ($p['registered_hours'] > 0 && $p['registered_hours'] < $min) {
            $reasons[] = ['code' => 'light_load', 'hours' => $p['registered_hours'], 'min' => $min];
        }
        if ($p['gpa_band'] === 'g1') {
            $reasons[] = ['code' => 'low_gpa', 'gpa' => $p['gpa']];
        }

        $termsNeeded = (int) ceil($p['remaining_hours'] / $avgLoad);
        $delayTerms = max(0, $termsNeeded - $p['plan_terms_left']);

        // Safety rule: never push a heavier load on a student who is already failing.
        $action = match (true) {
            self::isStruggling($p) => 'recovery_plan',
            $p['registered_hours'] < $min => 'reach_min_load',
            count($p['failed_pending']) > 0 => 'retake_failed',
            $p['gap_hours'] > 3 => 'add_course',
            default => 'keep_going',
        };

        return [
            'ready' => $cohort !== null,
            'profile' => array_diff_key($p, array_flip(['registered_course_ids', 'failed_pending'])),
            'cohort' => $cohort,
            'projection' => [
                'terms_needed' => $termsNeeded,
                'plan_terms_left' => $p['plan_terms_left'],
                'delay_terms' => $delayTerms,
                'assumed_load' => $avgLoad,
            ],
            'reasons' => $reasons,
            'action' => $action,
            'fail_impact' => $this->decisionRow('fail', $p),
        ];
    }

    /**
     * Academic time machine: everything the student needs to play "what if" with their road to graduation.
     * The arithmetic (hours left / load per term) runs in the browser so the timeline moves instantly;
     * this supplies the inputs plus what students like you went through after each kind of choice.
     */
    public function timeline(User $student): array
    {
        $p = $this->profile($student);
        $active = $this->registration->activeSemester();
        $insights = $this->courseInsights($student, $p['registered_course_ids']);

        $registered = Course::query()
            ->whereIn('id', $p['registered_course_ids'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'hours'])
            ->map(fn (Course $c) => $c->only(['id', 'code', 'name', 'hours']) + ['stats' => $insights[$c->id] ?? null])
            ->values()
            ->all();

        $cohort = $this->cohort($p);

        return [
            'term_no' => $p['term_no'],
            'remaining_hours' => $p['remaining_hours'],
            'plan_terms_left' => $p['plan_terms_left'],
            'registered_hours' => $p['registered_hours'],
            'registered' => $registered,
            'limits' => [
                'min_hours' => (int) config('academic.registration.min_hours'),
                'max_hours' => (int) config('academic.registration.max_hours'),
                'summer_max_hours' => (int) config('academic.registration.summer_max_hours'),
            ],
            // terms[0] is the current term; terms[k] is k main terms from now (summers skipped).
            'terms' => $active ? self::upcomingTerms($active->academic_year, $active->semester, 40) : [],
            'cohort' => $cohort ? ['n' => $cohort['n'], 'delayed' => $cohort['delayed']] : null,
            'evidence' => [
                'drop' => $this->decisionRow('drop', $p),
                'light' => $this->decisionRow('light', $p),
                'fail' => $this->decisionRow('fail', $p),
            ],
            'horizon_terms' => (int) config('twin.horizon_terms'),
        ];
    }

    /**
     * The next main terms, starting with the current one: 2026/2027 second → 2027/2028 first → 2027/2028 second ...
     *
     * @return array<int, array{academic_year: string, semester: string}>
     */
    public static function upcomingTerms(string $academicYear, string $semester, int $count): array
    {
        [$from] = array_map('intval', explode('/', $academicYear)) + [0];
        $year = $from;
        $terms = [];

        // A summer term counts as "now", then the next first term follows.
        if ($semester === 'summer') {
            $terms[] = ['academic_year' => $academicYear, 'semester' => 'summer'];
            $year++;
            $semester = 'first';
        }

        while (count($terms) < $count) {
            $terms[] = ['academic_year' => $year.'/'.($year + 1), 'semester' => $semester];
            if ($semester === 'second') {
                $year++;
            }
            $semester = $semester === 'first' ? 'second' : 'first';
        }

        return $terms;
    }

    /**
     * "Price of the decision": how students like you fared after the same choice, plus checked alternatives.
     */
    public function decision(User $student, string $action, ?int $courseId = null): array
    {
        $p = $this->profile($student);
        $min = (int) config('academic.registration.min_hours');
        $course = $courseId ? Course::query()->find($courseId, ['id', 'code', 'name', 'hours']) : null;
        $courseStats = $course ? $this->courseStats($course->id, $p['gpa_band']) : null;

        $hoursAfter = match ($action) {
            'drop' => $p['registered_hours'] - (int) ($course?->hours ?? 0),
            default => $p['registered_hours'],
        };

        $alternatives = [];

        if ($action === 'drop' && $course) {
            if ($courseStats) {
                $alternatives[] = ['type' => 'keep', 'pass_rate' => $courseStats['pass_rate'], 'typical_letter' => $courseStats['typical_letter']];
            }
            if ($instead = $this->cheaperDrop($p, $course->id, $courseStats)) {
                $alternatives[] = ['type' => 'drop_instead'] + $instead;
            }
        }

        $lightAfter = $hoursAfter < $min ? $this->decisionRow('light', $p) : null;
        if (in_array($action, ['drop', 'light'], true) && $hoursAfter < $min && ($add = $this->bestCourseToAdd($student, $p, $course?->id))) {
            $alternatives[] = ['type' => 'add_course'] + $add;
        }

        $result = [
            'action' => $action,
            'course' => $course?->only(['id', 'code', 'name', 'hours']),
            'cohort' => [
                'phase' => $p['phase'],
                'term_no' => $p['term_no'],
                'gpa_band' => $p['gpa_band'],
                'gpa_range' => $p['gpa_range'],
                'lane' => $p['lane'],
                'gap_hours' => $p['gap_hours'],
            ],
            'comparison' => $this->decisionRow($action === 'light' ? 'light' : $action, $p),
            'course_stats' => $courseStats,
            'hours_after' => $hoursAfter,
            'min_hours' => $min,
            'light_after' => $action === 'drop' ? $lightAfter : null,
            'alternatives' => $alternatives,
            'delayed_gap_hours' => (int) config('twin.delayed_gap_hours'),
            'horizon_terms' => (int) config('twin.horizon_terms'),
            'struggling' => self::isStruggling($p),
        ];

        // Lead with this exact course when enough students dropped and kept it; otherwise the general numbers, labelled as such.
        $result['headline'] = self::headline($action, $courseStats, $result['comparison']);

        // Tell the student up front whether confirming will notify their advisor.
        $result['will_notify_advisor'] = $action === 'drop' && $student->advisor_id !== null && AdvisingService::isCostly($result);
        // Lets the dialog offer "ask your advisor first" as a real next step.
        $result['has_advisor'] = $student->advisor_id !== null;

        return $result;
    }

    /**
     * "Students like you" figures for many courses at once (registration list).
     *
     * @param  array<int>  $courseIds
     * @return array<int, array>
     */
    public function courseInsights(User $student, array $courseIds): array
    {
        if (! $courseIds) {
            return [];
        }
        $band = $this->profile($student)['gpa_band'];
        $minCohort = (int) config('twin.min_cohort');

        return DB::table('twin_course_stats')
            ->whereIn('course_id', $courseIds)
            ->whereIn('gpa_band', [$band, 'all'])
            ->where('n_taken', '>=', $minCohort)
            ->get()
            ->groupBy('course_id')
            ->map(function (Collection $rows) use ($band) {
                $row = $rows->firstWhere('gpa_band', $band) ?? $rows->firstWhere('gpa_band', 'all');

                return $this->formatCourseRow($row);
            })
            ->all();
    }

    private function cohort(array $p): ?array
    {
        $row = DB::table('twin_cohorts')
            ->where(['phase' => $p['phase'], 'gpa_band' => $p['gpa_band'], 'lane' => $p['lane']])
            ->where('n', '>=', (int) config('twin.min_cohort'))
            ->first();

        return $row ? [
            'n' => (int) $row->n,
            'delayed' => round((float) $row->delayed, 3),
            'gap' => round((float) $row->gap, 1),
            'what_worked' => $this->whatWorked($row),
        ] : null;
    }

    /**
     * "What did the students who caught up do?" For the same cohort, compare students who were
     * on track two terms later with those who fell behind. Keep only clear differences (≥ 10 points)
     * in the on-time group's favour, so a discouraging number comes with a concrete step.
     *
     * @return array<int, array{code: string, on_time: float, delayed: float, n_on_time: int}>
     */
    private function whatWorked(object $row): array
    {
        if ((int) ($row->n_on_time ?? 0) < (int) config('twin.min_cohort')) {
            return [];
        }
        $items = [];
        foreach (['no_drop' => ['drop_on_time', 'drop_delayed'], 'no_fail' => ['fail_on_time', 'fail_delayed'], 'full_load' => ['light_on_time', 'light_delayed']] as $code => [$a, $b]) {
            if ($row->{$a} === null || $row->{$b} === null) {
                continue;
            }
            // Stored as "share who did X"; the habit is the opposite ("did not drop", "full load").
            $onTime = 1 - (float) $row->{$a};
            $delayed = 1 - (float) $row->{$b};
            if ($onTime - $delayed >= 0.10) {
                $items[] = ['code' => $code, 'on_time' => round($onTime, 3), 'delayed' => round($delayed, 3), 'n_on_time' => (int) $row->n_on_time];
            }
        }
        usort($items, fn ($x, $y) => ($y['on_time'] - $y['delayed']) <=> ($x['on_time'] - $x['delayed']));

        return array_slice($items, 0, 2);
    }

    /**
     * Log that a student saw the price of dropping a course (once per 10 minutes per course),
     * so the system's own effect can be measured: how often a drop followed.
     */
    public function logView(User $student, int $courseId, ?array $headline): void
    {
        $recent = DB::table('twin_decision_views')->where('student_id', $student->id)->where('course_id', $courseId)
            ->where('created_at', '>=', now()->subMinutes(10))->exists();
        if ($recent) {
            return;
        }
        $points = $headline ? ($headline['delayed_with'] - $headline['delayed_without']) * 100 : null;
        DB::table('twin_decision_views')->insert([
            'student_id' => $student->id,
            'course_id' => $courseId,
            'impact' => $points === null ? null : ($points >= 15 ? 'large' : ($points >= 5 ? 'medium' : 'small')),
            'dropped' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The drop went ahead: mark the most recent view of it (within a day). */
    public function markDropped(User $student, int $courseId): void
    {
        $id = DB::table('twin_decision_views')->where('student_id', $student->id)->where('course_id', $courseId)
            ->where('created_at', '>=', now()->subDay())->orderByDesc('id')->value('id');
        if ($id) {
            DB::table('twin_decision_views')->where('id', $id)->update(['dropped' => true, 'updated_at' => now()]);
        }
    }

    /**
     * The system's measured effect: of the times students saw the price of a drop,
     * how often they did not go ahead. Optionally limited to one advisor's students.
     */
    public function impact(?int $advisorId = null): array
    {
        $views = DB::table('twin_decision_views as v')
            ->when($advisorId, fn ($q) => $q->join('users as u', 'u.id', '=', 'v.student_id')->where('u.advisor_id', $advisorId));
        $total = (clone $views)->count();
        $dropped = (clone $views)->where('v.dropped', true)->count();
        $byImpact = (clone $views)->whereNotNull('v.impact')->groupBy('v.impact')
            ->selectRaw('v.impact, COUNT(*) as n, SUM(CASE WHEN v.dropped THEN 0 ELSE 1 END) as kept')->get()
            ->mapWithKeys(fn ($r) => [$r->impact => ['views' => (int) $r->n, 'kept' => (int) $r->kept]])->all();

        $alerts = DB::table('advisor_alerts')->when($advisorId, fn ($q) => $q->where('advisor_id', $advisorId));
        $alertTotal = (clone $alerts)->count();
        $contacted = (clone $alerts)->whereExists(fn ($q) => $q->from('advisor_notes as n')
            ->whereColumn('n.student_id', 'advisor_alerts.student_id')->whereColumn('n.advisor_id', 'advisor_alerts.advisor_id')
            ->where('n.contacted', true)->whereColumn('n.created_at', '>=', 'advisor_alerts.created_at'))->count();

        return [
            'views' => $total,
            'kept' => $total - $dropped,
            'dropped' => $dropped,
            'kept_rate' => $total ? round(($total - $dropped) / $total, 3) : null,
            'by_impact' => $byImpact,
            'alerts' => $alertTotal,
            'alerts_contacted' => $contacted,
        ];
    }

    private function decisionRow(string $decision, array $p): ?array
    {
        $min = (int) config('twin.min_cohort');
        $row = DB::table('twin_decision_stats')
            ->where(['decision' => $decision, 'phase' => $p['phase'], 'gpa_band' => $p['gpa_band'], 'lane' => $p['lane']])
            ->where('n_with', '>=', $min)
            ->where('n_without', '>=', $min)
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'n_with' => (int) $row->n_with,
            'delayed_with' => round((float) $row->delayed_with, 3),
            'gap_with' => round((float) $row->gap_with, 1),
            'n_without' => (int) $row->n_without,
            'delayed_without' => round((float) $row->delayed_without, 3),
            'gap_without' => round((float) $row->gap_without, 1),
        ];
    }

    private function courseStats(int $courseId, string $band): ?array
    {
        $min = (int) config('twin.min_cohort');
        $rows = DB::table('twin_course_stats')->where('course_id', $courseId)->whereIn('gpa_band', [$band, 'all'])->get();
        $row = $rows->first(fn ($r) => $r->gpa_band === $band && $r->n_taken >= $min)
            ?? $rows->first(fn ($r) => $r->gpa_band === 'all' && $r->n_taken >= $min);

        return $row ? $this->formatCourseRow($row) : null;
    }

    private function formatCourseRow(object $row): array
    {
        $min = (int) config('twin.min_cohort');
        $dropKnown = $row->n_dropped >= $min && $row->delayed_if_dropped !== null;

        return [
            'band' => $row->gpa_band,
            'n_taken' => (int) $row->n_taken,
            'pass_rate' => round((float) $row->pass_rate, 3),
            'typical_letter' => $row->avg_points !== null ? self::letterFor((float) $row->avg_points) : null,
            'n_dropped' => (int) $row->n_dropped,
            'n_kept' => (int) ($row->n_kept ?? 0),
            'delayed_if_dropped' => $dropKnown ? round((float) $row->delayed_if_dropped, 3) : null,
            'delayed_if_kept' => $dropKnown && $row->delayed_if_kept !== null ? round((float) $row->delayed_if_kept, 3) : null,
        ];
    }

    /** Another registered course whose drop cost students like you clearly less (≥ 5 points). */
    private function cheaperDrop(array $p, int $courseId, ?array $stats): ?array
    {
        $baseline = $stats['delayed_if_dropped'] ?? null;
        if ($baseline === null) {
            return null;
        }
        $others = array_values(array_diff($p['registered_course_ids'], [$courseId]));
        $best = null;
        foreach ($others as $otherId) {
            $s = $this->courseStats($otherId, $p['gpa_band']);
            if ($s && $s['delayed_if_dropped'] !== null && $s['delayed_if_dropped'] <= $baseline - 0.05
                && (! $best || $s['delayed_if_dropped'] < $best['delayed'])) {
                $best = ['course_id' => $otherId, 'delayed' => $s['delayed_if_dropped']];
            }
        }
        if (! $best) {
            return null;
        }
        $course = Course::query()->find($best['course_id'], ['id', 'code', 'name', 'hours']);

        return ['course' => $course?->only(['id', 'code', 'name', 'hours']), 'delayed' => $best['delayed']];
    }

    /**
     * Rule-checked suggestion: a course the student can actually register now
     * (in plan, not passed, active, fits the max hours), with the best pass rate for students like you.
     */
    private function bestCourseToAdd(User $student, array $p, ?int $excludeId): ?array
    {
        $overview = $this->registration->overview($student);
        $hoursAfter = $p['registered_hours'] - ($excludeId && in_array($excludeId, $p['registered_course_ids'], true)
            ? (int) Course::query()->whereKey($excludeId)->value('hours') : 0);
        $max = (int) $overview['limits']['max_hours'];
        $min = (int) $overview['limits']['min_hours'];
        $struggling = self::isStruggling($p);

        $candidates = collect($overview['available'])
            ->filter(fn ($c) => $c['id'] !== $excludeId && $hoursAfter + $c['hours'] <= $max && ($c['is_recommended'] || $c['is_retake']));
        if ($candidates->isEmpty()) {
            return null;
        }
        $insights = $this->courseInsights($student, $candidates->pluck('id')->all());

        // Struggling students: retake a failed course first. Then prefer what actually closes the gap, then pass rate.
        $best = $candidates->sortByDesc(fn ($c) => [
            $struggling && $c['is_retake'] ? 1 : 0,
            $hoursAfter + $c['hours'] >= $min ? 1 : 0,
            $insights[$c['id']]['pass_rate'] ?? 0,
        ])->first();
        $total = $hoursAfter + $best['hours'];

        return [
            'course' => ['id' => $best['id'], 'code' => $best['code'], 'name' => $best['name'], 'hours' => $best['hours']],
            'pass_rate' => $insights[$best['id']]['pass_rate'] ?? null,
            'is_retake' => (bool) $best['is_retake'],
            'hours_after_adding' => $total,
            'reaches_min' => $total >= $min,
            'shortfall_after' => max(0, $min - $total),
        ];
    }

    private static function letterFor(float $points): string
    {
        foreach ([[3.875, 'A+'], [3.625, 'A'], [3.25, 'B+'], [2.75, 'B'], [2.25, 'C+'], [1.75, 'C'], [1.25, 'D+'], [0.5, 'D']] as [$cut, $letter]) {
            if ($points >= $cut) {
                return $letter;
            }
        }

        return 'F';
    }
}
