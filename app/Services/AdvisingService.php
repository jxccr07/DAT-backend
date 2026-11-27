<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closes the loop between the student and their advisor: when a student goes
 * ahead with a decision that cost similar students noticeably more delay, the
 * advisor is told, with the same numbers the student saw.
 */
class AdvisingService
{
    /** Extra hours behind plan (vs similar students who didn't drop) that count as costly. */
    public const COSTLY_EXTRA_HOURS = 2.0;

    /** Or: this exact course's drop was linked to ≥ 10 points more students falling a semester behind. */
    public const COSTLY_COURSE_DELTA = 0.10;

    /**
     * Would going ahead with this drop notify the advisor? The student is told
     * this up front in the "price of the decision" dialog (no surprises).
     */
    public static function isCostly(array $preview): bool
    {
        [$extra, $courseDelta, $belowMin] = self::measures($preview);

        return $extra >= self::COSTLY_EXTRA_HOURS || $courseDelta >= self::COSTLY_COURSE_DELTA || $belowMin;
    }

    /** @return array{0: float, 1: float, 2: bool} extra hours, course delta, below minimum load */
    private static function measures(array $preview): array
    {
        $comparison = $preview['comparison'] ?? null;
        $stats = $preview['course_stats'] ?? null;

        return [
            $comparison ? $comparison['gap_with'] - $comparison['gap_without'] : 0.0,
            isset($stats['delayed_if_dropped'], $stats['delayed_if_kept']) ? $stats['delayed_if_dropped'] - $stats['delayed_if_kept'] : 0.0,
            $preview['hours_after'] < $preview['min_hours'],
        ];
    }

    public function alertOnDrop(User $student, Course $course, ?array $preview): ?int
    {
        if (! $student->advisor_id || ! $preview || ! self::isCostly($preview)) {
            return null;
        }

        $comparison = $preview['comparison'] ?? null;
        $stats = $preview['course_stats'] ?? null;
        [$extra, , $belowMin] = self::measures($preview);

        return DB::table('advisor_alerts')->insertGetId([
            'advisor_id' => $student->advisor_id,
            'student_id' => $student->id,
            'type' => 'costly_drop',
            'course_id' => $course->id,
            'details' => json_encode([
                'extra_hours' => round($extra, 1),
                'delayed_with' => $comparison['delayed_with'] ?? null,
                'delayed_without' => $comparison['delayed_without'] ?? null,
                'n_with' => $comparison['n_with'] ?? null,
                'course_delayed_if_dropped' => $stats['delayed_if_dropped'] ?? null,
                'course_delayed_if_kept' => $stats['delayed_if_kept'] ?? null,
                'hours_after' => $preview['hours_after'],
                'min_hours' => $preview['min_hours'],
                'below_min' => $belowMin,
                // The same headline numbers the student saw before confirming.
                'headline' => $preview['headline'] ?? null,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
