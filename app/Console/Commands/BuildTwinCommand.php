<?php

namespace App\Console\Commands;

use App\Models\Semester;
use App\Twin\TwinModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the academic twin's statistics from every historical academic record.
 *
 * For every student and every term we take a snapshot of where they stood
 * before the term (GPA band, how far behind plan), what they decided during it
 * (dropped a course, took a light load, failed a course) and where they ended
 * up two terms later. Grouping those snapshots gives "students like you"
 * comparisons. A held-out 20% of students is used to measure accuracy.
 */
class BuildTwinCommand extends Command
{
    protected $signature = 'twin:build {--students= : Only use students with id <= this value (quick dev builds)}';

    protected $description = 'Rebuild the academic twin statistics from historical academic records';

    private const WORK_TABLES = ['twin_w_expected', 'twin_w_terms', 'twin_w_cum', 'twin_w_snap', 'twin_w_band', 'twin_w_taken', 'twin_w_dropped', 'twin_w_model', 'twin_w_last', 'twin_w_failed', 'twin_w_current', 'twin_w_tmodel'];

    public function handle(): int
    {
        $started = microtime(true);
        $limit = $this->option('students') !== null ? (int) $this->option('students') : null;
        $activeId = (int) (Semester::query()->where('is_active', true)->orderByDesc('starts_at')->value('id') ?? 0);
        $minHours = (int) config('academic.registration.min_hours');
        $delayGap = (int) config('twin.delayed_gap_hours');
        $horizon = (int) config('twin.horizon_terms');
        $studentFilter = $limit ? " AND r.student_id <= {$limit}" : '';

        $this->dropWorkTables();

        try {
            $this->step('Expected plan hours per term', fn () => $this->buildExpected());

            $points = TwinModel::pointsSql('r.grade');
            $this->step('Term summaries', fn () => DB::statement(<<<SQL
                CREATE TABLE twin_w_terms AS
                SELECT r.student_id, r.semester_id,
                    ROW_NUMBER() OVER (PARTITION BY r.student_id ORDER BY r.semester_id) AS term_no,
                    SUM(CASE WHEN r.status IN ('passed', 'failed') THEN c.hours ELSE 0 END) AS taken_h,
                    SUM(CASE WHEN r.status = 'passed' THEN c.hours ELSE 0 END) AS passed_h,
                    SUM(CASE WHEN r.status = 'failed' THEN 1 ELSE 0 END) AS failed_n,
                    SUM(CASE WHEN r.status = 'deleted_course' THEN 1 ELSE 0 END) AS dropped_n,
                    SUM(CASE WHEN r.status IN ('passed', 'failed') AND r.grade IS NOT NULL THEN c.hours ELSE 0 END) AS graded_h,
                    SUM(CASE WHEN r.status IN ('passed', 'failed') AND r.grade IS NOT NULL THEN c.hours * {$points} ELSE 0 END) AS qp
                FROM student_academic_records r
                JOIN courses c ON c.id = r.course_id
                WHERE r.semester_id <> {$activeId}{$studentFilter}
                GROUP BY r.student_id, r.semester_id
            SQL));

            $this->step('Cumulative progress', function () {
                DB::statement(<<<'SQL'
                    CREATE TABLE twin_w_cum AS
                    SELECT t.student_id, t.semester_id, t.term_no, t.taken_h, t.failed_n, t.dropped_n,
                        SUM(t.qp) OVER w AS cum_qp,
                        SUM(t.graded_h) OVER w AS cum_gh,
                        e.hours - SUM(t.passed_h) OVER w AS gap
                    FROM twin_w_terms t
                    JOIN users u ON u.id = t.student_id
                    JOIN twin_w_expected e ON e.plan_id = u.academic_plan_id AND e.k = t.term_no
                    WINDOW w AS (PARTITION BY t.student_id ORDER BY t.term_no)
                SQL);
                DB::statement('CREATE INDEX twin_w_cum_idx ON twin_w_cum (student_id, term_no)');
            });

            $phase = TwinModel::phaseSql('d.term_no');
            $band = TwinModel::gpaBandSql('p.cum_qp', 'p.cum_gh');
            $lane = TwinModel::laneSql('p.gap');

            $this->step('Decision snapshots', function () use ($phase, $band, $lane, $minHours, $delayGap, $horizon) {
                DB::statement(<<<SQL
                    CREATE TABLE twin_w_snap AS
                    SELECT d.student_id, d.semester_id, d.term_no,
                        {$phase} AS phase, {$band} AS gpa_band, {$lane} AS lane,
                        CASE WHEN d.dropped_n > 0 THEN 1 ELSE 0 END AS is_drop,
                        CASE WHEN d.taken_h < {$minHours} THEN 1 ELSE 0 END AS is_light,
                        CASE WHEN d.failed_n > 0 THEN 1 ELSE 0 END AS is_fail,
                        a.gap AS gap_after,
                        CASE WHEN a.gap >= {$delayGap} THEN 1 ELSE 0 END AS delayed
                    FROM twin_w_cum d
                    LEFT JOIN twin_w_cum p ON p.student_id = d.student_id AND p.term_no = d.term_no - 1
                    JOIN twin_w_cum a ON a.student_id = d.student_id AND a.term_no = d.term_no + {$horizon}
                SQL);
                DB::statement('CREATE INDEX twin_w_snap_idx ON twin_w_snap (student_id, semester_id)');

                DB::statement(<<<SQL
                    CREATE TABLE twin_w_band AS
                    SELECT d.student_id, d.semester_id, {$band} AS gpa_band
                    FROM twin_w_cum d
                    LEFT JOIN twin_w_cum p ON p.student_id = d.student_id AND p.term_no = d.term_no - 1
                SQL);
                DB::statement('CREATE INDEX twin_w_band_idx ON twin_w_band (student_id, semester_id)');
            });

            $recordFilter = $limit ? " AND r.student_id <= {$limit}" : '';
            $this->step('Course statistics', function () use ($points, $recordFilter, $activeId) {
                DB::statement(<<<SQL
                    CREATE TABLE twin_w_taken AS
                    SELECT r.course_id, b.gpa_band, COUNT(*) AS n_taken,
                        AVG(CASE WHEN r.status = 'passed' THEN 1.0 ELSE 0.0 END) AS pass_rate,
                        AVG(CASE WHEN r.grade IS NOT NULL THEN {$points} END) AS avg_points
                    FROM student_academic_records r
                    JOIN twin_w_band b ON b.student_id = r.student_id AND b.semester_id = r.semester_id
                    WHERE r.status IN ('passed', 'failed') AND r.semester_id <> {$activeId}{$recordFilter}
                    GROUP BY r.course_id, b.gpa_band
                SQL);

                DB::statement(<<<SQL
                    CREATE TABLE twin_w_dropped AS
                    SELECT r.course_id, s.gpa_band,
                        SUM(CASE WHEN r.status = 'deleted_course' THEN 1 ELSE 0 END) AS n_dropped,
                        SUM(CASE WHEN r.status = 'deleted_course' THEN s.delayed ELSE 0 END) AS delayed_dropped,
                        SUM(CASE WHEN r.status <> 'deleted_course' THEN 1 ELSE 0 END) AS n_kept,
                        SUM(CASE WHEN r.status <> 'deleted_course' THEN s.delayed ELSE 0 END) AS delayed_kept
                    FROM student_academic_records r
                    JOIN twin_w_snap s ON s.student_id = r.student_id AND s.semester_id = r.semester_id
                    WHERE r.status IN ('passed', 'failed', 'deleted_course') AND r.semester_id <> {$activeId}{$recordFilter}
                    GROUP BY r.course_id, s.gpa_band
                SQL);
            });

            $this->step('Model accuracy on held-out students', fn () => $this->backtest());

            $this->step('Saving statistics', fn () => DB::transaction(function () use ($limit, $activeId, $started, $delayGap, $horizon, $minHours) {
                $this->saveStats();
                $this->saveMeta($limit, $activeId, $started, $delayGap, $horizon, $minHours);
            }));

            $this->step('Current standing of every student', fn () => $this->saveStudentStatus($activeId, $limit));
        } finally {
            $this->dropWorkTables();
        }

        $this->info(sprintf('Twin built in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }

    private array $backtest = [];

    private function buildExpected(): void
    {
        Schema::create('twin_w_expected', function ($table) {
            $table->unsignedBigInteger('plan_id');
            $table->unsignedInteger('k');
            $table->unsignedInteger('hours');
        });

        $rows = DB::table('academic_plan_courses as p')
            ->join('courses as c', 'c.id', '=', 'p.course_id')
            ->groupBy('p.academic_plan_id', 'p.suggested_semester')
            ->select('p.academic_plan_id', 'p.suggested_semester', DB::raw('SUM(c.hours) as hours'))
            ->get()
            ->groupBy('academic_plan_id');

        $insert = [];
        foreach ($rows as $planId => $terms) {
            $byTerm = $terms->pluck('hours', 'suggested_semester');
            $running = 0;
            // Terms past the plan's length still expect the whole plan to be done.
            foreach (range(1, 16) as $k) {
                $running += (int) ($byTerm[$k] ?? 0);
                $insert[] = ['plan_id' => $planId, 'k' => $k, 'hours' => $running];
            }
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('twin_w_expected')->insert($chunk);
        }
    }

    /**
     * Trains on 80% of students and scores the other 20% with what is known
     * once add/drop closes (standing before the term + drop / light-load
     * choices), predicting "a semester or more behind plan two terms later".
     */
    private function backtest(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE twin_w_model AS
            SELECT phase, gpa_band, lane, is_drop, is_light, AVG(delayed * 1.0) AS p, COUNT(*) AS n
            FROM twin_w_snap
            WHERE student_id % 5 <> 0
            GROUP BY phase, gpa_band, lane, is_drop, is_light
        SQL);

        $groups = DB::select(<<<'SQL'
            SELECT m.p AS score, s.delayed AS delayed, COUNT(*) AS n
            FROM twin_w_snap s
            JOIN twin_w_model m ON m.phase = s.phase AND m.gpa_band = s.gpa_band AND m.lane = s.lane
                AND m.is_drop = s.is_drop AND m.is_light = s.is_light
            WHERE s.student_id % 5 = 0
            GROUP BY m.p, s.delayed
        SQL);

        $this->backtest = self::scoreBacktest(array_map(fn ($g) => [(float) $g->score, (int) $g->delayed, (int) $g->n], $groups));

        // Naive baseline for comparison: "how far behind are you already" alone, no GPA and no decisions.
        $baseline = DB::select(<<<'SQL'
            SELECT m.p AS score, s.delayed AS delayed, COUNT(*) AS n
            FROM twin_w_snap s
            JOIN (
                SELECT phase, lane, AVG(delayed * 1.0) AS p FROM twin_w_snap WHERE student_id % 5 <> 0 GROUP BY phase, lane
            ) m ON m.phase = s.phase AND m.lane = s.lane
            WHERE s.student_id % 5 = 0
            GROUP BY m.p, s.delayed
        SQL);

        $this->backtest['baseline'] = array_intersect_key(
            self::scoreBacktest(array_map(fn ($g) => [(float) $g->score, (int) $g->delayed, (int) $g->n], $baseline)),
            array_flip(['auc', 'auc_ci', 'brier', 'accuracy', 'precision', 'recall'])
        );

        $this->backtest['temporal'] = $this->temporalBacktest();
    }

    /**
     * Out-of-time check, closer to real use than a random split: score the latest
     * decisions whose outcome is known, using only outcomes that were already
     * visible at that point (decisions at least `horizon` terms earlier).
     */
    private function temporalBacktest(): ?array
    {
        $horizon = (int) config('twin.horizon_terms');
        $testSemester = (int) DB::table('twin_w_snap')->max('semester_id');
        $trainUpTo = $testSemester - $horizon;
        if ($trainUpTo < (int) DB::table('twin_w_snap')->min('semester_id')) {
            return null;
        }

        DB::statement(<<<SQL
            CREATE TABLE twin_w_tmodel AS
            SELECT phase, gpa_band, lane, is_drop, is_light, AVG(delayed * 1.0) AS p
            FROM twin_w_snap
            WHERE semester_id <= {$trainUpTo}
            GROUP BY phase, gpa_band, lane, is_drop, is_light
        SQL);

        $groups = DB::select(<<<SQL
            SELECT m.p AS score, s.delayed AS delayed, COUNT(*) AS n
            FROM twin_w_snap s
            JOIN twin_w_tmodel m ON m.phase = s.phase AND m.gpa_band = s.gpa_band AND m.lane = s.lane
                AND m.is_drop = s.is_drop AND m.is_light = s.is_light
            WHERE s.semester_id = {$testSemester}
            GROUP BY m.p, s.delayed
        SQL);

        $result = self::scoreBacktest(array_map(fn ($g) => [(float) $g->score, (int) $g->delayed, (int) $g->n], $groups));

        // No matching group means the later term holds a stage the earlier terms never saw: every student
        // entered together (one cohort), so time and study stage move in lockstep and cannot be separated.
        $available = $result['students_scored'] > 0;

        return ['available' => $available, 'reason' => $available ? null : 'single_cohort', 'train_up_to_semester' => $trainUpTo, 'test_semester' => $testSemester] + $result;
    }

    /**
     * @param  array<int, array{0: float, 1: int, 2: int}>  $groups  [score, actual, count]
     */
    public static function scoreBacktest(array $groups): array
    {
        $positives = $negatives = 0;
        $tp = $fp = $fn = $tn = 0;
        $bins = array_fill(0, 5, ['n' => 0, 'predicted' => 0.0, 'actual' => 0]);
        $byScore = [];
        $squaredError = 0.0;

        foreach ($groups as [$score, $actual, $n]) {
            $actual ? $positives += $n : $negatives += $n;
            $squaredError += ($score - $actual) ** 2 * $n;
            $flag = $score >= 0.5;
            if ($flag && $actual) $tp += $n;
            if ($flag && ! $actual) $fp += $n;
            if (! $flag && $actual) $fn += $n;
            if (! $flag && ! $actual) $tn += $n;

            $bin = min(4, (int) floor($score * 5));
            $bins[$bin]['n'] += $n;
            $bins[$bin]['predicted'] += $score * $n;
            $bins[$bin]['actual'] += $actual * $n;

            $key = sprintf('%.6f', $score);
            $byScore[$key] ??= [0, 0];
            $byScore[$key][$actual ? 0 : 1] += $n;
        }

        // AUC: chance a random delayed student scores above a random on-track one (ties count half).
        ksort($byScore, SORT_NUMERIC);
        $auc = 0.0;
        $negBelow = 0;
        foreach ($byScore as [$pos, $neg]) {
            $auc += $pos * ($negBelow + $neg / 2);
            $negBelow += $neg;
        }
        $total = $positives + $negatives;
        $aucValue = $positives && $negatives ? $auc / ($positives * $negatives) : null;

        return [
            'students_scored' => $total,
            'base_rate' => $total ? round($positives / $total, 3) : null,
            'auc' => $aucValue !== null ? round($aucValue, 3) : null,
            'auc_ci' => $aucValue !== null ? self::aucInterval($aucValue, $positives, $negatives) : null,
            // Mean squared gap between predicted chance and what happened (0 = perfect, 0.25 = coin flip at 50%).
            'brier' => $total ? round($squaredError / $total, 3) : null,
            'accuracy' => $total ? round(($tp + $tn) / $total, 3) : null,
            'precision' => $tp + $fp ? round($tp / ($tp + $fp), 3) : null,
            'recall' => $tp + $fn ? round($tp / ($tp + $fn), 3) : null,
            'calibration' => array_values(array_filter(array_map(fn ($b, $i) => $b['n'] ? [
                'range' => [$i / 5, ($i + 1) / 5],
                'n' => $b['n'],
                'predicted' => round($b['predicted'] / $b['n'], 3),
                'actual' => round($b['actual'] / $b['n'], 3),
            ] : null, $bins, array_keys($bins)))),
        ];
    }

    /**
     * 95% interval for an AUC (Hanley & McNeil 1982). Snapshots of the same student are
     * not independent, so the true interval is somewhat wider than this.
     *
     * @return array{0: float, 1: float}
     */
    public static function aucInterval(float $auc, int $positives, int $negatives): array
    {
        $q1 = $auc / (2 - $auc);
        $q2 = 2 * $auc ** 2 / (1 + $auc);
        $variance = ($auc * (1 - $auc) + ($positives - 1) * ($q1 - $auc ** 2) + ($negatives - 1) * ($q2 - $auc ** 2)) / ($positives * $negatives);
        $margin = 1.96 * sqrt(max(0.0, $variance));

        return [round(max(0.0, $auc - $margin), 3), round(min(1.0, $auc + $margin), 3)];
    }

    private function saveStats(): void
    {
        DB::table('twin_decision_stats')->delete();
        DB::table('twin_course_stats')->delete();
        DB::table('twin_cohorts')->delete();

        foreach (['drop' => 'is_drop', 'light' => 'is_light', 'fail' => 'is_fail'] as $decision => $flag) {
            DB::statement(<<<SQL
                INSERT INTO twin_decision_stats (decision, phase, gpa_band, lane, n_with, delayed_with, gap_with, n_without, delayed_without, gap_without)
                SELECT '{$decision}', phase, gpa_band, lane,
                    SUM({$flag}),
                    SUM({$flag} * delayed) * 1.0 / SUM({$flag}),
                    SUM({$flag} * gap_after) * 1.0 / SUM({$flag}),
                    SUM(1 - {$flag}),
                    SUM((1 - {$flag}) * delayed) * 1.0 / SUM(1 - {$flag}),
                    SUM((1 - {$flag}) * gap_after) * 1.0 / SUM(1 - {$flag})
                FROM twin_w_snap
                GROUP BY phase, gpa_band, lane
                HAVING SUM({$flag}) > 0 AND SUM(1 - {$flag}) > 0
            SQL);
        }

        DB::statement(<<<'SQL'
            INSERT INTO twin_cohorts (phase, gpa_band, lane, n, delayed, gap, n_on_time,
                drop_on_time, drop_delayed, light_on_time, light_delayed, fail_on_time, fail_delayed)
            SELECT phase, gpa_band, lane, COUNT(*), AVG(delayed * 1.0), AVG(gap_after * 1.0),
                SUM(1 - delayed),
                SUM((1 - delayed) * is_drop) * 1.0 / NULLIF(SUM(1 - delayed), 0),
                SUM(delayed * is_drop) * 1.0 / NULLIF(SUM(delayed), 0),
                SUM((1 - delayed) * is_light) * 1.0 / NULLIF(SUM(1 - delayed), 0),
                SUM(delayed * is_light) * 1.0 / NULLIF(SUM(delayed), 0),
                SUM((1 - delayed) * is_fail) * 1.0 / NULLIF(SUM(1 - delayed), 0),
                SUM(delayed * is_fail) * 1.0 / NULLIF(SUM(delayed), 0)
            FROM twin_w_snap
            GROUP BY phase, gpa_band, lane
        SQL);

        // One row per course and band, plus an 'all' row as a fallback for small bands.
        foreach (['band' => 't.gpa_band', 'all' => "'all'"] as $mode => $bandExpr) {
            $group = $mode === 'band' ? 't.course_id, t.gpa_band' : 't.course_id';
            $dropGroup = $mode === 'band' ? 'course_id, gpa_band' : 'course_id';
            $dropBand = $mode === 'band' ? 'gpa_band' : "'all'";
            DB::statement(<<<SQL
                INSERT INTO twin_course_stats (course_id, gpa_band, n_taken, pass_rate, avg_points, n_dropped, delayed_if_dropped, delayed_if_kept, n_kept)
                SELECT t.course_id, {$bandExpr},
                    SUM(t.n_taken),
                    SUM(t.pass_rate * t.n_taken) / SUM(t.n_taken),
                    SUM(t.avg_points * t.n_taken) / SUM(t.n_taken),
                    COALESCE(MAX(d.n_dropped), 0),
                    MAX(d.delayed_if_dropped),
                    MAX(d.delayed_if_kept),
                    COALESCE(MAX(d.n_kept), 0)
                FROM twin_w_taken t
                LEFT JOIN (
                    SELECT course_id, {$dropBand} AS gpa_band,
                        SUM(n_dropped) AS n_dropped,
                        SUM(n_kept) AS n_kept,
                        CASE WHEN SUM(n_dropped) > 0 THEN SUM(delayed_dropped) * 1.0 / SUM(n_dropped) END AS delayed_if_dropped,
                        CASE WHEN SUM(n_kept) > 0 THEN SUM(delayed_kept) * 1.0 / SUM(n_kept) END AS delayed_if_kept
                    FROM twin_w_dropped
                    GROUP BY {$dropGroup}
                ) d ON d.course_id = t.course_id AND d.gpa_band = {$bandExpr}
                GROUP BY {$group}
            SQL);
        }
    }

    /**
     * One row per student describing where they stand now (same grouping as the history),
     * with the share of similar students who ended up a semester or more behind as `risk`.
     */
    private function saveStudentStatus(int $activeId, ?int $limit): void
    {
        $recordFilter = $limit ? " AND student_id <= {$limit}" : '';
        $userFilter = $limit ? " AND u.id <= {$limit}" : '';
        $minCohort = (int) config('twin.min_cohort');

        DB::statement('CREATE TABLE twin_w_last AS SELECT student_id, MAX(term_no) AS term_no FROM twin_w_cum GROUP BY student_id');
        DB::statement('CREATE INDEX twin_w_last_idx ON twin_w_last (student_id)');

        // Same rule as CourseRegistrationService::courseStatuses: failed, never passed, not retaking now.
        DB::statement(<<<SQL
            CREATE TABLE twin_w_failed AS
            SELECT student_id, COUNT(*) AS n FROM (
                SELECT student_id, course_id FROM student_academic_records
                WHERE 1 = 1{$recordFilter}
                GROUP BY student_id, course_id
                HAVING SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) > 0
                    AND SUM(CASE WHEN status IN ('passed', 'current_course') THEN 1 ELSE 0 END) = 0
            ) f GROUP BY student_id
        SQL);
        DB::statement('CREATE INDEX twin_w_failed_idx ON twin_w_failed (student_id)');

        DB::statement(<<<SQL
            CREATE TABLE twin_w_current AS
            SELECT r.student_id, SUM(c.hours) AS hours
            FROM student_academic_records r JOIN courses c ON c.id = r.course_id
            WHERE r.semester_id = {$activeId} AND r.status = 'current_course'{$recordFilter}
            GROUP BY r.student_id
        SQL);
        DB::statement('CREATE INDEX twin_w_current_idx ON twin_w_current (student_id)');

        $termNo = 'COALESCE(l.term_no, 0) + 1';
        $phase = TwinModel::phaseSql($termNo);
        $band = TwinModel::gpaBandSql('cu.cum_qp', 'cu.cum_gh');
        $gap = 'CASE WHEN COALESCE(cu.gap, 0) < 0 THEN 0 ELSE COALESCE(cu.gap, 0) END';
        $lane = TwinModel::laneSql($gap);
        $now = now()->toDateTimeString();

        DB::transaction(function () use ($termNo, $phase, $band, $gap, $lane, $now, $userFilter, $minCohort) {
            DB::table('twin_student_status')->delete();
            DB::statement(<<<SQL
                INSERT INTO twin_student_status (student_id, term_no, gpa, gpa_band, lane, gap_hours, failed_pending, registered_hours, risk, updated_at)
                SELECT u.id, {$termNo},
                    CASE WHEN cu.cum_gh > 0 THEN ROUND(cu.cum_qp * 1.0 / cu.cum_gh, 2) END,
                    {$band}, {$lane}, {$gap},
                    COALESCE(f.n, 0), COALESCE(cr.hours, 0),
                    co.delayed, '{$now}'
                FROM users u
                LEFT JOIN twin_w_last l ON l.student_id = u.id
                LEFT JOIN twin_w_cum cu ON cu.student_id = u.id AND cu.term_no = l.term_no
                LEFT JOIN twin_w_failed f ON f.student_id = u.id
                LEFT JOIN twin_w_current cr ON cr.student_id = u.id
                LEFT JOIN twin_cohorts co ON co.phase = {$phase} AND co.gpa_band = {$band} AND co.lane = {$lane} AND co.n >= {$minCohort}
                WHERE u.role = 'student'{$userFilter}
            SQL);
        });
    }

    private function saveMeta(?int $limit, int $activeId, float $started, int $delayGap, int $horizon, int $minHours): void
    {
        $value = [
            'built_at' => now()->toIso8601String(),
            'build_seconds' => round(microtime(true) - $started, 1),
            'sample_limit' => $limit,
            'students' => (int) DB::table('twin_w_terms')->distinct()->count('student_id'),
            'terms' => (int) DB::table('twin_w_terms')->count(),
            'records' => (int) DB::table('student_academic_records')
                ->where('semester_id', '<>', $activeId)
                ->when($limit, fn ($q) => $q->where('student_id', '<=', $limit))
                ->count(),
            'snapshots' => (int) DB::table('twin_w_snap')->count(),
            'delayed_gap_hours' => $delayGap,
            'horizon_terms' => $horizon,
            'light_load_below' => $minHours,
            'min_cohort' => (int) config('twin.min_cohort'),
            'backtest' => $this->backtest,
        ];

        DB::table('twin_meta')->updateOrInsert(['key' => 'summary'], ['value' => json_encode($value)]);
    }

    private function step(string $label, callable $callback): void
    {
        $t = microtime(true);
        $this->components->task($label, function () use ($callback) {
            $callback();

            return true;
        });
        $this->line(sprintf('  <fg=gray>%.1fs</>', microtime(true) - $t), verbosity: \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
    }

    private function dropWorkTables(): void
    {
        foreach (self::WORK_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
}
