<?php

namespace App\Twin;

/**
 * Shared definitions of the academic twin, used both by the SQL that builds the
 * statistics (twin:build) and by the PHP that places a live student in a cohort.
 * Keeping them in one place guarantees a student is matched exactly the way the
 * historical data was grouped.
 */
final class TwinModel
{
    public const DECISIONS = ['drop', 'light', 'fail'];

    /** Grade (0-100) to grade points; mirrors StudentController::gradeScale. */
    public static function points(float $grade): float
    {
        return match (true) {
            $grade >= 95 => 4.00,
            $grade >= 90 => 3.75,
            $grade >= 85 => 3.50,
            $grade >= 80 => 3.00,
            $grade >= 75 => 2.50,
            $grade >= 70 => 2.00,
            $grade >= 65 => 1.50,
            $grade >= 60 => 1.00,
            default => 0.00,
        };
    }

    public static function pointsSql(string $grade): string
    {
        return "(CASE WHEN {$grade} >= 95 THEN 4.0 WHEN {$grade} >= 90 THEN 3.75 WHEN {$grade} >= 85 THEN 3.5"
            ." WHEN {$grade} >= 80 THEN 3.0 WHEN {$grade} >= 75 THEN 2.5 WHEN {$grade} >= 70 THEN 2.0"
            ." WHEN {$grade} >= 65 THEN 1.5 WHEN {$grade} >= 60 THEN 1.0 ELSE 0.0 END)";
    }

    /** Which part of the degree the student is in, by their own term number (1st, 2nd, ... term). */
    public static function phase(int $termNo): string
    {
        return match (true) {
            $termNo <= 2 => 'early',
            $termNo <= 4 => 'mid',
            default => 'late',
        };
    }

    public static function phaseSql(string $termNo): string
    {
        return "(CASE WHEN {$termNo} <= 2 THEN 'early' WHEN {$termNo} <= 4 THEN 'mid' ELSE 'late' END)";
    }

    /** Cumulative GPA band before the term (`new` when nothing is graded yet). */
    public static function gpaBand(?float $gpa): string
    {
        return match (true) {
            $gpa === null => 'new',
            $gpa < 2.0 => 'g1',
            $gpa < 2.5 => 'g2',
            $gpa < 3.0 => 'g3',
            $gpa < 3.5 => 'g4',
            default => 'g5',
        };
    }

    public static function gpaBandSql(string $qualityPoints, string $gradedHours): string
    {
        $gpa = "({$qualityPoints} * 1.0 / {$gradedHours})";

        return "(CASE WHEN {$gradedHours} IS NULL OR {$gradedHours} = 0 THEN 'new'"
            ." WHEN {$gpa} < 2.0 THEN 'g1' WHEN {$gpa} < 2.5 THEN 'g2' WHEN {$gpa} < 3.0 THEN 'g3'"
            ." WHEN {$gpa} < 3.5 THEN 'g4' ELSE 'g5' END)";
    }

    /** How far behind the plan the student already is (hours not yet passed vs the plan). */
    public static function lane(int $gapHours): string
    {
        return match (true) {
            $gapHours <= 3 => 'on',
            $gapHours <= 9 => 'slight',
            default => 'behind',
        };
    }

    public static function laneSql(string $gap): string
    {
        return "(CASE WHEN COALESCE({$gap}, 0) <= 3 THEN 'on' WHEN {$gap} <= 9 THEN 'slight' ELSE 'behind' END)";
    }

    /** GPA range of a band, for display ("2.5 – 3.0"). */
    public static function bandRange(string $band): ?array
    {
        return [
            'g1' => [0.0, 2.0],
            'g2' => [2.0, 2.5],
            'g3' => [2.5, 3.0],
            'g4' => [3.0, 3.5],
            'g5' => [3.5, 4.0],
        ][$band] ?? null;
    }
}
