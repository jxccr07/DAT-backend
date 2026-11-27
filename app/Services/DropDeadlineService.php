<?php

namespace App\Services;

use App\Models\Semester;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Talk to them before the drop deadline": a short, ranked list of an advisor's students
 * for whom dropping (or failing) a current course looks costly, with the reason in plain
 * numbers. The list only suggests; the advisor decides, and a human conversation is the
 * intervention (automated mass reminders showed no effect in large trials).
 *
 * Built from registration and grade data only. Looking at the "price of the decision"
 * screen never puts a student on this list; asking for a meeting does, by the student's choice.
 */
class DropDeadlineService
{
    /** How many students an advisor is shown per day, so each gets a real conversation. */
    public const DAILY_CAP = 10;

    /** Students contacted (or set aside with a reason) this recently are left out. */
    public const QUIET_DAYS = 14;

    /** A course counts as risky to drop when similar students who dropped it were this much more often a term behind. */
    public const DROP_DELTA = 0.10;

    /** ...or risky to keep when this share of similar students failed it. */
    public const FAIL_SHARE = 0.20;

    public function __construct(private readonly CourseRegistrationService $registration)
    {
    }

    public function deadline(?Semester $semester): ?CarbonImmutable
    {
        if ($fixed = config('academic.registration.drop_deadline')) {
            return CarbonImmutable::parse($fixed)->endOfDay();
        }
        if (! $semester?->ends_at) {
            return null;
        }

        return CarbonImmutable::parse($semester->ends_at)
            ->subDays((int) config('academic.registration.drop_deadline_days_before_end'))
            ->endOfDay();
    }

    /** The student asks for a conversation from the drop screen (their choice, so it is allowed to reach the advisor). */
    public function requestMeeting(User $student, ?int $courseId): ?int
    {
        if (! $student->advisor_id) {
            return null;
        }

        $open = DB::table('advisor_alerts')->where('advisor_id', $student->advisor_id)->where('student_id', $student->id)
            ->where('type', 'meeting_request')->whereNull('seen_at')->first();
        if ($open) {
            DB::table('advisor_alerts')->where('id', $open->id)->update(['course_id' => $courseId ?? $open->course_id, 'updated_at' => now()]);

            return (int) $open->id;
        }

        return DB::table('advisor_alerts')->insertGetId([
            'advisor_id' => $student->advisor_id,
            'student_id' => $student->id,
            'type' => 'meeting_request',
            'course_id' => $courseId,
            'details' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function forAdvisor(User $advisor): array
    {
        $semester = $this->registration->activeSemester();
        $deadline = $this->deadline($semester);
        $today = CarbonImmutable::now()->startOfDay();
        $min = (int) config('academic.registration.min_hours');
        $minCohort = (int) config('twin.min_cohort');
        $quietSince = now()->subDays(self::QUIET_DAYS);

        $base = [
            'deadline' => $deadline?->toDateString(),
            'days_left' => $deadline ? (int) $today->diffInDays($deadline->startOfDay(), false) : null,
            'daily_cap' => self::DAILY_CAP,
            'quiet_days' => self::QUIET_DAYS,
            'total' => 0,
            'students' => [],
        ];
        if (! $semester || ($base['days_left'] !== null && $base['days_left'] < 0)) {
            return $base;
        }

        $students = DB::table('users as u')
            ->join('twin_student_status as s', 's.student_id', '=', 'u.id')
            ->where('u.advisor_id', $advisor->id)->where('u.role', User::ROLE_STUDENT)
            ->select('u.id', 'u.full_name', 'u.email', 's.gpa', 's.gpa_band', 's.registered_hours', 's.failed_pending', 's.risk')
            ->get()->keyBy('id');
        if ($students->isEmpty()) {
            return $base;
        }
        $ids = $students->keys()->all();

        // Contacted or set aside recently (any note), unless they asked for a meeting since.
        $quiet = DB::table('advisor_notes')->where('advisor_id', $advisor->id)->whereIn('student_id', $ids)
            ->where('created_at', '>=', $quietSince)->groupBy('student_id')->selectRaw('student_id, MAX(created_at) as at')
            ->pluck('at', 'student_id');
        $requests = DB::table('advisor_alerts')->where('advisor_id', $advisor->id)->whereIn('student_id', $ids)
            ->where('type', 'meeting_request')->whereNull('seen_at')
            ->get(['student_id', 'course_id', 'updated_at'])->keyBy('student_id');

        $courses = DB::table('student_academic_records as r')->join('courses as c', 'c.id', '=', 'r.course_id')
            ->whereIn('r.student_id', $ids)->where('r.semester_id', $semester->id)
            ->where('r.status', CourseRegistrationService::STATUS_CURRENT)
            ->get(['r.student_id', 'c.id', 'c.code', 'c.name', 'c.hours'])->groupBy('student_id');

        $stats = DB::table('twin_course_stats')
            ->whereIn('course_id', $courses->flatten()->pluck('id')->unique()->all())
            ->where('n_taken', '>=', $minCohort)
            ->get()->groupBy(fn ($r) => $r->course_id.'|'.$r->gpa_band);

        $rows = [];
        foreach ($students as $id => $st) {
            $requested = $requests->get($id);
            if ($quiet->has($id) && ! ($requested && $requested->updated_at > $quiet->get($id))) {
                continue;
            }

            // The current course whose drop or failure would weigh most on this student.
            $best = null;
            foreach ($courses->get($id, collect()) as $c) {
                $row = ($stats->get($c->id.'|'.$st->gpa_band) ?? $stats->get($c->id.'|all'))?->first();
                $dropKnown = $row && $row->n_dropped >= $minCohort && $row->delayed_if_dropped !== null && $row->delayed_if_kept !== null;
                $delta = $dropKnown ? (float) $row->delayed_if_dropped - (float) $row->delayed_if_kept : null;
                $failShare = $row ? 1 - (float) $row->pass_rate : null;
                $hoursAfter = (int) $st->registered_hours - (int) $c->hours;
                // Only when this drop is what takes them under the minimum (already-light loads are on the weekly list).
                $belowMin = (int) $st->registered_hours >= $min && $hoursAfter < $min;
                $isRequested = $requested && (int) $requested->course_id === (int) $c->id;

                // The decision itself leads: what dropping this course cost students like them, then crossing the minimum, then the odds of failing it.
                $score = max(0.0, $delta ?? 0.0) * 100 + ($belowMin ? 15 : 0) + ($failShare ?? 0) * 30 + ($isRequested ? 5 : 0);
                if (! $best || $score > $best['score']) {
                    $best = compact('c', 'row', 'delta', 'failShare', 'hoursAfter', 'belowMin', 'score');
                }
            }

            $qualifies = $requested || ($best && (($best['delta'] ?? 0) >= self::DROP_DELTA || ($best['failShare'] ?? 0) >= self::FAIL_SHARE || $best['belowMin']));
            if (! $qualifies) {
                continue;
            }

            $reasons = [];
            if ($requested) {
                $reasons[] = ['code' => 'requested'];
            }
            if ($best && ($best['delta'] ?? 0) >= self::DROP_DELTA) {
                $reasons[] = ['code' => 'drop_cost', 'a' => round((float) $best['row']->delayed_if_dropped, 3), 'b' => round((float) $best['row']->delayed_if_kept, 3), 'n' => (int) $best['row']->n_dropped];
            }
            if ($best && $best['belowMin']) {
                $reasons[] = ['code' => 'below_min', 'hours' => $best['hoursAfter'], 'min' => $min];
            }
            if ($best && ($best['failShare'] ?? 0) >= self::FAIL_SHARE) {
                $reasons[] = ['code' => 'fail_rate', 'p' => round($best['failShare'], 3)];
            }
            if ((int) $st->failed_pending > 0) {
                $reasons[] = ['code' => 'failed', 'count' => (int) $st->failed_pending];
            }

            $rows[] = [
                'id' => (int) $id,
                'student_id' => explode('@', $st->email)[0],
                'full_name' => $st->full_name,
                'gpa' => $st->gpa !== null ? round((float) $st->gpa, 2) : null,
                'course' => $best ? ['id' => (int) $best['c']->id, 'code' => $best['c']->code, 'name' => $best['c']->name, 'hours' => (int) $best['c']->hours] : null,
                'requested' => (bool) $requested,
                'reasons' => $reasons,
                // Older failures and overall risk only break ties: they belong to the weekly list, not to this decision.
                'score' => ($requested ? 1000 : 0) + ($best['score'] ?? 0) + min(3, (int) $st->failed_pending) + (float) ($st->risk ?? 0) * 5,
            ];
        }

        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);

        return ['total' => count($rows), 'students' => array_map(
            fn ($r) => array_diff_key($r, ['score' => true]),
            array_slice($rows, 0, self::DAILY_CAP),
        )] + $base;
    }
}
