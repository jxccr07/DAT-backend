<?php

namespace App\Support;

use App\Models\User;
use App\Services\CourseRegistrationService;
use App\Twin\TwinService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The demo pool's current-term registrations as they were when the demo was prepared, so
 * demo:tidy can put back what visitors added or dropped. Kept in files next to the demo
 * baseline (no schema change): one snapshot, plus one small "last change" file per student
 * that the registration service writes on every add/drop while the public demo is on.
 */
class DemoRegistrations
{
    private const SNAPSHOT_FILE = 'demo-registrations.json';

    private const TOUCH_DIR = 'demo-registration-touches';

    public static function active(): bool
    {
        return config('academic.demo.enabled') && DemoAccounts::isPinned();
    }

    /** Save the pool's active-term records (every status: a re-registration can reuse a dropped row). */
    public static function snapshot(int $advisorId): void
    {
        $semester = app(CourseRegistrationService::class)->activeSemester();
        $students = DB::table('users')->where('advisor_id', $advisorId)->where('role', User::ROLE_STUDENT)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $rows = $semester ? DB::table('student_academic_records')->where('semester_id', $semester->id)
            ->whereIn('student_id', DB::table('users')->where('advisor_id', $advisorId)->where('role', User::ROLE_STUDENT)->select('id'))
            ->orderBy('student_id')->orderBy('course_id')
            ->get(['student_id', 'course_id', 'status', 'grade'])
            ->map(fn ($r) => ['student_id' => (int) $r->student_id, 'course_id' => (int) $r->course_id, 'status' => $r->status, 'grade' => $r->grade])
            ->all() : [];

        Storage::disk('local')->put(self::SNAPSHOT_FILE, json_encode([
            'advisor_id' => $advisorId,
            'semester_id' => $semester?->id,
            'taken_at' => now()->toDateTimeString(),
            'students' => $students,
            'rows' => $rows,
        ]));
    }

    /** Note that a demo-pool student changed their registration (called before the change is made). */
    public static function touch(User $student): void
    {
        if (! self::active() || ! $student->advisor_id || (int) $student->advisor_id !== DemoAccounts::advisorId()) {
            return;
        }
        Storage::disk('local')->put(self::TOUCH_DIR.'/'.$student->id, now()->toDateTimeString());
    }

    public static function touchedAt(int $studentId): ?Carbon
    {
        $disk = Storage::disk('local');
        $path = self::TOUCH_DIR.'/'.$studentId;

        return $disk->exists($path) ? Carbon::parse(trim((string) $disk->get($path))) : null;
    }

    /**
     * Put back the snapshot for every pool student whose registration differs from it and who
     * has made no change since $cutoff. Returns how many students were restored.
     */
    public static function restore(int $advisorId, Carbon $cutoff): int
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::SNAPSHOT_FILE)) {
            self::snapshot($advisorId); // first run: today's state is the prepared one

            return 0;
        }
        $snapshot = json_decode((string) $disk->get(self::SNAPSHOT_FILE), true);
        $semester = app(CourseRegistrationService::class)->activeSemester();

        // Taken for another advisor or another term: nothing safe to put back.
        if (! is_array($snapshot) || ($snapshot['advisor_id'] ?? null) !== $advisorId
            || ! $semester || ($snapshot['semester_id'] ?? null) !== $semester->id) {
            return 0;
        }

        $wanted = collect($snapshot['rows'])->groupBy('student_id');
        // Only students who were in the pool when the snapshot was taken and still are.
        $students = DB::table('users')->where('advisor_id', $advisorId)->where('role', User::ROLE_STUDENT)
            ->whereIn('id', $snapshot['students'])->pluck('id')->map(fn ($id) => (int) $id);

        $current = DB::table('student_academic_records')->where('semester_id', $semester->id)
            ->whereIn('student_id', $students)->get(['student_id', 'course_id', 'status', 'grade', 'updated_at'])
            ->groupBy('student_id');

        $key = fn ($rows) => collect($rows)->map(fn ($r) => ((array) $r)['course_id'].':'.((array) $r)['status'])->sort()->values()->all();
        $restored = 0;

        foreach ($students as $id) {
            $now = $current->get($id, collect());
            $want = $wanted->get($id, collect());
            if ($key($now) === $key($want)) {
                continue;
            }
            // A visitor is still at it: leave their walkthrough alone until it has been quiet for a while.
            $lastChange = collect([self::touchedAt($id), ...$now->pluck('updated_at')->filter()->map(fn ($t) => Carbon::parse($t))])
                ->filter()->max();
            if ($lastChange && $lastChange->gte($cutoff)) {
                continue;
            }

            DB::transaction(function () use ($id, $semester, $want) {
                DB::table('student_academic_records')->where('student_id', $id)->where('semester_id', $semester->id)
                    ->whereNotIn('course_id', $want->pluck('course_id'))->delete();
                foreach ($want as $row) {
                    $match = ['student_id' => $id, 'course_id' => $row['course_id'], 'semester_id' => $semester->id];
                    $exists = DB::table('student_academic_records')->where($match)->exists();
                    DB::table('student_academic_records')->updateOrInsert($match, [
                        'status' => $row['status'], 'grade' => $row['grade'], 'updated_at' => now(),
                    ] + ($exists ? [] : ['created_at' => now()]));
                }
            });

            if ($student = User::query()->find($id)) {
                app(TwinService::class)->refreshStatus($student);
            }
            $restored++;
        }

        return $restored;
    }
}
