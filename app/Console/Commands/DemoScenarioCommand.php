<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Services\AdvisingService;
use App\Services\CourseRegistrationService;
use App\Twin\TwinService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prepares a demo advisor with real decision alerts: a few of their students
 * go through the actual drop flow (same price-of-decision numbers, same alert
 * rule), so the advisor view has something genuine to show from the first click.
 */
class DemoScenarioCommand extends Command
{
    protected $signature = 'advising:demo-scenario {--advisor= : Advisor email (default: first advisor)} {--alerts=4 : How many alerts to create}';

    protected $description = 'Run a few real drops for one advisor\'s students so the demo advisor has alerts';

    public function handle(TwinService $twin, CourseRegistrationService $registration, AdvisingService $advising): int
    {
        if (! $twin->isReady()) {
            $this->error('Build the twin first: php artisan twin:build');

            return self::FAILURE;
        }

        $advisor = $this->option('advisor')
            ? User::query()->where('email', $this->option('advisor'))->where('role', User::ROLE_ADVISOR)->first()
            : User::query()->where('role', User::ROLE_ADVISOR)->orderBy('id')->first();
        if (! $advisor) {
            $this->error('No advisor found. Run php artisan advising:assign first.');

            return self::FAILURE;
        }

        $semester = $registration->activeSemester();
        $want = max(1, (int) $this->option('alerts'));
        $made = 0;

        // Students with courses this term, most behind first. For each, try their courses and keep the
        // first drop that has its own course numbers (enough history) and a visible difference: an honest demo.
        $candidates = DB::table('users as u')
            ->join('twin_student_status as s', 's.student_id', '=', 'u.id')
            ->where('u.advisor_id', $advisor->id)
            ->whereNotExists(fn ($q) => $q->from('advisor_alerts as a')->whereColumn('a.student_id', 'u.id'))
            ->where('s.registered_hours', '>=', 5)
            ->orderByDesc('s.gap_hours')
            ->limit(400)->pluck('u.id');

        foreach ($candidates as $id) {
            if ($made >= $want) {
                break;
            }
            $student = User::query()->find($id);
            $courseIds = StudentAcademicRecord::query()->where('student_id', $id)->where('semester_id', $semester?->id)
                ->where('status', CourseRegistrationService::STATUS_CURRENT)->inRandomOrder()->pluck('course_id');

            foreach ($courseIds as $courseId) {
                $preview = $twin->decision($student, 'drop', $courseId);
                $h = $preview['headline'];
                if (! $h || $h['source'] !== 'course' || $h['delayed_with'] - $h['delayed_without'] < 0.05 || ! AdvisingService::isCostly($preview)) {
                    continue;
                }
                $course = Course::query()->find($courseId);
                $registration->drop($student, $courseId);
                $advising->alertOnDrop($student, $course, $preview);
                $twin->refreshStatus($student);
                $made++;
                $this->line(sprintf('  %s dropped %s (%d%% vs %d%%)', $student->full_name, $course->code, $h['delayed_with'] * 100, $h['delayed_without'] * 100));
                break;
            }
        }

        $this->info("{$made} alerts for {$advisor->full_name} <{$advisor->email}> (password: ".config('academic.demo.password').')');

        return self::SUCCESS;
    }
}
