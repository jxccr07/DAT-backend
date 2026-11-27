<?php

use App\Console\Commands\BuildTwinCommand;
use App\Models\Semester;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Twin\TwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A tiny history: 3 past terms, 40 classmates. Half of them dropped a course in
 * their first term and ended up further behind; the other half did not.
 */
function twinWorld(): array
{
    $world = academicWorld();
    config(['twin.min_cohort' => 1]);

    $terms = collect([
        $world['past'],
        Semester::create(['name' => 'Past 2', 'academic_year' => '2025/2026', 'semester' => 'second', 'starts_at' => '2026-01-20', 'ends_at' => '2026-05-20', 'is_active' => false]),
        Semester::create(['name' => 'Past 3', 'academic_year' => '2026/2027', 'semester' => 'summer', 'starts_at' => '2026-06-01', 'ends_at' => '2026-07-30', 'is_active' => false]),
    ]);
    $c = $world['courses'];

    foreach (range(1, 40) as $i) {
        $student = User::factory()->create(['academic_plan_id' => $world['plan']->id]);
        $dropper = $i % 2 === 0;
        $rec = fn ($course, $term, $status, $grade = null) => StudentAcademicRecord::create([
            'student_id' => $student->id, 'course_id' => $course->id, 'semester_id' => $term->id, 'status' => $status, 'grade' => $grade,
        ]);

        $rec($c[0], $terms[0], 'passed', 88);
        $rec($c[1], $terms[0], $dropper ? 'deleted_course' : 'passed', $dropper ? null : 85);
        $rec($c[2], $terms[1], 'passed', 80);
        $rec($c[3], $terms[1], $dropper ? 'failed' : 'passed', $dropper ? 50 : 82);
        $rec($c[4], $terms[2], 'passed', 78);
    }

    return $world + ['terms' => $terms];
}

it('builds the twin and measures accuracy on held-out students', function () {
    twinWorld();

    $this->artisan('twin:build')->assertSuccessful();

    $meta = json_decode(DB::table('twin_meta')->value('value'), true);
    expect($meta['students'])->toBe(40)
        ->and($meta['snapshots'])->toBe(40)
        ->and($meta['backtest'])->toHaveKeys(['auc', 'auc_ci', 'brier', 'accuracy', 'precision', 'recall', 'baseline', 'temporal'])
        ->and(DB::table('twin_decision_stats')->where('decision', 'drop')->value('n_with'))->toBe(20)
        ->and(Schema::hasTable('twin_w_snap'))->toBeFalse();
});

it('shows a student how classmates like them fared after dropping a course', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $this->artisan('twin:build')->assertSuccessful();

    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[1]->id, 'semester_id' => $now->id, 'status' => 'current_course']);
    Sanctum::actingAs($student);

    $response = $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[1]->id)->assertOk();

    $response->assertJsonPath('data.cohort.phase', 'early')
        ->assertJsonPath('data.comparison.n_with', 20)
        ->assertJsonPath('data.comparison.n_without', 20)
        ->assertJsonPath('data.hours_after', 0);

    expect($response->json('data.comparison.gap_with'))->toBeGreaterThan($response->json('data.comparison.gap_without'));
});

it('returns a status card and attaches course insights to registration', function () {
    ['student' => $student] = twinWorld();
    $this->artisan('twin:build')->assertSuccessful();
    Sanctum::actingAs($student);

    $this->getJson('/api/v1/me/twin')->assertOk()
        ->assertJsonPath('data.ready', true)
        ->assertJsonPath('data.profile.term_no', 1)
        ->assertJsonStructure(['data' => ['profile', 'cohort', 'projection', 'reasons', 'action']]);

    $available = $this->getJson('/api/v1/me/registration')->assertOk()->json('data.available');
    expect($available[0])->toHaveKey('twin')
        ->and($available[0]['twin']['n_taken'])->toBe(40);
});

it('validates decision requests and requires sign-in', function () {
    ['student' => $student] = twinWorld();
    $this->getJson('/api/v1/me/twin')->assertUnauthorized();

    Sanctum::actingAs($student);
    $this->getJson('/api/v1/me/twin/decision?action=nap')->assertUnprocessable();
    $this->getJson('/api/v1/me/twin/decision?action=drop')->assertUnprocessable();
});

it('reports not ready before the twin is built', function () {
    ['student' => $student] = academicWorld();
    Sanctum::actingAs($student);

    $this->getJson('/api/v1/me/twin')->assertOk()->assertJsonPath('data.ready', false);
});

it('computes AUC with ties counted half', function () {
    // Perfect separation.
    expect(BuildTwinCommand::scoreBacktest([[0.9, 1, 10], [0.1, 0, 10]])['auc'])->toBe(1.0)
        // Everyone gets the same score: no better than chance.
        ->and(BuildTwinCommand::scoreBacktest([[0.5, 1, 10], [0.5, 0, 10]])['auc'])->toBe(0.5);
});

it('reports how sure the numbers are: Brier score, AUC interval and Wilson ranges', function () {
    // Brier: always saying 50% costs 0.25; perfect confident predictions cost 0.
    expect(BuildTwinCommand::scoreBacktest([[0.5, 1, 10], [0.5, 0, 10]])['brier'])->toBe(0.25)
        ->and(BuildTwinCommand::scoreBacktest([[1.0, 1, 10], [0.0, 0, 10]])['brier'])->toEqual(0);

    // More students -> narrower interval around the same AUC.
    [$lo1, $hi1] = BuildTwinCommand::aucInterval(0.8, 50, 50);
    [$lo2, $hi2] = BuildTwinCommand::aucInterval(0.8, 5000, 5000);
    expect($lo1)->toBeLessThan(0.8)->and($hi1)->toBeGreaterThan(0.8)
        ->and($hi2 - $lo2)->toBeLessThan($hi1 - $lo1);

    // 12% of 393 students: roughly 9%-16%; the range never leaves [0, 1].
    [$lo, $hi] = TwinService::wilson(0.12, 393);
    expect($lo)->toBeGreaterThan(0.08)->toBeLessThan(0.12)
        ->and($hi)->toBeGreaterThan(0.12)->toBeLessThan(0.17)
        ->and(TwinService::wilson(0.0, 100)[0])->toEqual(0)
        ->and(TwinService::wilson(0.5, 0))->toBeNull();
});

it('logs each view of a drop\'s price once, marks it when the drop goes ahead, and reports the impact', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $this->artisan('twin:build')->assertSuccessful();
    foreach ([1, 2] as $i) {
        StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[$i]->id, 'semester_id' => $now->id, 'status' => 'current_course']);
    }
    Sanctum::actingAs($student);

    // Looked at both, twice for the first one (no double count), then dropped only the second.
    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[1]->id)->assertOk();
    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[1]->id)->assertOk();
    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[2]->id)->assertOk();
    $this->deleteJson('/api/v1/me/registration/courses/'.$c[2]->id)->assertNoContent();

    expect(DB::table('twin_decision_views')->count())->toBe(2);
    $this->getJson('/api/v1/me/twin/meta')->assertOk()
        ->assertJsonPath('data.impact.views', 2)
        ->assertJsonPath('data.impact.kept', 1)
        ->assertJsonPath('data.impact.dropped', 1)
        ->assertJsonPath('data.impact.kept_rate', 0.5);
});

it('shows what students who caught up did differently', function () {
    ['student' => $student] = twinWorld();
    config(['twin.delayed_gap_hours' => 6]); // droppers end up "delayed" in this tiny world
    $this->artisan('twin:build')->assertSuccessful();
    Sanctum::actingAs($student);

    $worked = collect($this->getJson('/api/v1/me/twin')->json('data.cohort.what_worked'));
    $noDrop = $worked->firstWhere('code', 'no_drop');

    expect($noDrop)->not->toBeNull()
        ->and($noDrop['on_time'])->toEqual(1)     // everyone who stayed on track kept all courses
        ->and($noDrop['delayed'])->toEqual(0);    // everyone who fell behind had dropped one
});

it('gives the time machine what it needs to project graduation', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $this->artisan('twin:build')->assertSuccessful();
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[5]->id, 'semester_id' => $now->id, 'status' => 'current_course']);
    Sanctum::actingAs($student);

    $data = $this->getJson('/api/v1/me/twin/timeline')->assertOk()->json('data');

    expect($data['registered_hours'])->toBe(3)
        ->and($data['registered'][0]['code'])->toBe('CS006')
        ->and($data['limits'])->toHaveKeys(['min_hours', 'max_hours', 'summer_max_hours'])
        ->and($data['terms'][0])->toBe(['academic_year' => '2026/2027', 'semester' => 'first'])
        ->and($data['evidence'])->toHaveKeys(['drop', 'light', 'fail'])
        ->and($data['evidence']['drop']['n_with'])->toBe(20);

    // Playing with scenarios is private: nothing is logged.
    expect(DB::table('twin_decision_views')->count())->toBe(0);
    $this->getJson('/api/v1/me/twin/timeline')->assertOk();
});

it('lists upcoming main terms in order, skipping summers', function () {
    expect(TwinService::upcomingTerms('2026/2027', 'second', 3))->toBe([
        ['academic_year' => '2026/2027', 'semester' => 'second'],
        ['academic_year' => '2027/2028', 'semester' => 'first'],
        ['academic_year' => '2027/2028', 'semester' => 'second'],
    ])->and(TwinService::upcomingTerms('2026/2027', 'summer', 2))->toBe([
        ['academic_year' => '2026/2027', 'semester' => 'summer'],
        ['academic_year' => '2027/2028', 'semester' => 'first'],
    ]);
});

it('keeps the time machine behind sign-in', function () {
    twinWorld();
    $this->getJson('/api/v1/me/twin/timeline')->assertUnauthorized();
});

it('only logs a drop\'s price view for one of the student\'s own current courses', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now, 'past' => $past] = twinWorld();
    $this->artisan('twin:build')->assertSuccessful();
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[1]->id, 'semester_id' => $now->id, 'status' => 'current_course']);
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[3]->id, 'semester_id' => $past->id, 'status' => 'failed']);
    Sanctum::actingAs($student);

    // Not taking it, or only took it in an earlier term: the preview still answers, but nothing is counted.
    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[2]->id)->assertOk();
    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[3]->id)->assertOk();
    expect(DB::table('twin_decision_views')->count())->toBe(0);

    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[1]->id)->assertOk();
    expect(DB::table('twin_decision_views')->pluck('course_id')->all())->toBe([$c[1]->id]);
});
