<?php

use App\Models\StudentAcademicRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function advisor(array $attributes = []): User
{
    return User::factory()->create(['role' => User::ROLE_ADVISOR] + $attributes);
}

it('assigns every student of a plan to an advisor, about caseload each', function () {
    ['plan' => $plan] = academicWorld();
    User::factory()->count(9)->create(['academic_plan_id' => $plan->id]);

    $this->artisan('advising:assign', ['--caseload' => 4])->assertSuccessful();

    $advisors = User::query()->where('role', 'advisor')->get();
    expect($advisors)->toHaveCount(3) // 10 students / 4 per advisor
        ->and(User::query()->where('role', 'student')->whereNull('advisor_id')->count())->toBe(0)
        ->and(User::query()->where('role', 'student')->groupBy('advisor_id')->pluck(DB::raw('COUNT(*)'))->max())->toBeLessThanOrEqual(4);

    // Re-running reuses the advisors instead of creating more.
    $this->artisan('advising:assign', ['--caseload' => 4])->assertSuccessful();
    expect(User::query()->where('role', 'advisor')->count())->toBe(3);
});

it('only lets advisors in, and only to their own students', function () {
    ['student' => $student] = academicWorld();
    $mine = advisor();
    $other = advisor();
    $student->forceFill(['advisor_id' => $other->id])->save();

    Sanctum::actingAs($student);
    $this->getJson('/api/v1/me/advisor')->assertForbidden();

    Sanctum::actingAs($mine);
    $this->getJson('/api/v1/me/advisor')->assertOk()->assertJsonPath('data.counts.students', 0);
    $this->getJson("/api/v1/me/advisor/students/{$student->id}")->assertNotFound();
    $this->postJson("/api/v1/me/advisor/students/{$student->id}/notes", ['body' => 'hi'])->assertNotFound();
});

it('ranks an advisor\'s students by risk after the twin is built', function () {
    ['student' => $student] = twinWorld();
    $advisor = advisor();
    User::query()->where('role', 'student')->update(['advisor_id' => $advisor->id]);
    $this->artisan('twin:build')->assertSuccessful();

    Sanctum::actingAs($advisor);
    $page = $this->getJson('/api/v1/me/advisor/students')->assertOk();

    expect($page->json('meta.total'))->toBe(41);
    $risks = collect($page->json('data'))->pluck('risk')->filter(fn ($r) => $r !== null)->values();
    expect($risks->all())->toBe($risks->sortDesc()->values()->all());

    $this->getJson("/api/v1/me/advisor/students/{$student->id}")->assertOk()
        ->assertJsonPath('data.student.student_id', '300000123')
        ->assertJsonPath('data.twin.ready', true);
});

it('alerts the advisor when a student goes ahead with a costly drop, and contact settles it', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $advisor = advisor();
    $student->forceFill(['advisor_id' => $advisor->id])->save();
    $this->artisan('twin:build')->assertSuccessful();
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[1]->id, 'semester_id' => $now->id, 'status' => 'current_course']);

    Sanctum::actingAs($student);
    $this->deleteJson("/api/v1/me/registration/courses/{$c[1]->id}?notify_advisor=1")->assertNoContent();

    $alert = DB::table('advisor_alerts')->first();
    expect($alert->advisor_id)->toBe($advisor->id)
        ->and($alert->student_id)->toBe($student->id)
        ->and(json_decode($alert->details, true)['below_min'])->toBeTrue();

    Sanctum::actingAs($advisor);
    $this->getJson('/api/v1/me/advisor')->assertJsonPath('data.counts.unseen_alerts', 1)
        ->assertJsonPath('data.alerts.0.course.code', $c[1]->code);

    $this->postJson("/api/v1/me/advisor/students/{$student->id}/notes", ['body' => 'Called, will retake next term.', 'contacted' => true])
        ->assertCreated();

    $this->getJson('/api/v1/me/advisor')->assertJsonPath('data.counts.unseen_alerts', 0);
    expect(DB::table('advisor_notes')->count())->toBe(1);
});

it('does not alert the advisor unless the student asks for it', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $student->forceFill(['advisor_id' => advisor()->id])->save();
    $this->artisan('twin:build')->assertSuccessful();
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[1]->id, 'semester_id' => $now->id, 'status' => 'current_course']);

    Sanctum::actingAs($student);
    $this->deleteJson("/api/v1/me/registration/courses/{$c[1]->id}")->assertNoContent();

    expect(DB::table('advisor_alerts')->count())->toBe(0);
});

it('does not alert anyone for students without an advisor', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $this->artisan('twin:build')->assertSuccessful();
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[1]->id, 'semester_id' => $now->id, 'status' => 'current_course']);

    Sanctum::actingAs($student);
    $this->deleteJson("/api/v1/me/registration/courses/{$c[1]->id}?notify_advisor=1")->assertNoContent();

    expect(DB::table('advisor_alerts')->count())->toBe(0);
});

it('lets an advisor mark only their own alerts as seen', function () {
    ['student' => $student] = academicWorld();
    $mine = advisor();
    $other = advisor();
    $id = DB::table('advisor_alerts')->insertGetId(['advisor_id' => $other->id, 'student_id' => $student->id, 'type' => 'costly_drop', 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs($mine);
    $this->postJson("/api/v1/me/advisor/alerts/{$id}/seen")->assertNotFound();

    Sanctum::actingAs($other);
    $this->postJson("/api/v1/me/advisor/alerts/{$id}/seen")->assertOk();
});

it('keeps students and advisors on their own sign-in pages', function () {
    ['student' => $student] = academicWorld();
    $advisor = advisor(['email' => 'advisor1.1@example.com']);

    $this->postJson('/api/v1/me/login', ['email' => $advisor->email, 'password' => 'password', 'portal' => 'student'])
        ->assertUnprocessable()->assertJsonPath('code', 'wrong_portal_advisor');
    $this->postJson('/api/v1/me/login', ['email' => $student->email, 'password' => 'password', 'portal' => 'advisor'])
        ->assertUnprocessable()->assertJsonPath('code', 'wrong_portal_student');

    // A wrong password never reveals which kind of account it is.
    $this->postJson('/api/v1/me/login', ['email' => $advisor->email, 'password' => 'nope', 'portal' => 'student'])
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_credentials');

    $this->postJson('/api/v1/me/login', ['email' => $advisor->email, 'password' => 'password', 'portal' => 'advisor'])
        ->assertOk()->assertJsonPath('data.user.role', 'advisor');
    expect(\Laravel\Sanctum\PersonalAccessToken::count())->toBe(1);
});

it('hands out a demo advisor only in demo mode, preferring one with alerts', function () {
    ['student' => $student] = academicWorld();
    advisor();
    $busy = advisor();
    DB::table('advisor_alerts')->insert(['advisor_id' => $busy->id, 'student_id' => $student->id, 'type' => 'costly_drop', 'created_at' => now(), 'updated_at' => now()]);

    config(['academic.demo.enabled' => false]);
    $this->getJson('/api/v1/demo-advisor')->assertNotFound();

    config(['academic.demo.enabled' => true]);
    $this->getJson('/api/v1/demo-advisor')->assertOk()
        ->assertJsonPath('data.email', $busy->email)
        ->assertJsonPath('data.password', config('academic.demo.password'));
});

it('never claims an added course reaches the minimum when it does not', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $this->artisan('twin:build')->assertSuccessful();
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[1]->id, 'semester_id' => $now->id, 'status' => 'current_course']);

    Sanctum::actingAs($student);
    $alt = collect($this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[1]->id)->json('data.alternatives'))
        ->firstWhere('type', 'add_course');

    // 0 hours left after the drop + one 3-hour course = 3 < 12.
    expect($alt['hours_after_adding'])->toBe(3)
        ->and($alt['reaches_min'])->toBeFalse()
        ->and($alt['shortfall_after'])->toBe(9);
});

it('tells the student up front when confirming will notify their advisor', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $this->artisan('twin:build')->assertSuccessful();
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[1]->id, 'semester_id' => $now->id, 'status' => 'current_course']);

    Sanctum::actingAs($student);
    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[1]->id)
        ->assertJsonPath('data.will_notify_advisor', false)
        ->assertJsonPath('data.has_advisor', false);

    $student->forceFill(['advisor_id' => advisor()->id])->save();
    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[1]->id)
        ->assertJsonPath('data.will_notify_advisor', true)
        ->assertJsonPath('data.has_advisor', true);
});

it('suggests a recovery plan, not a heavier load, to a failing student', function () {
    ['student' => $student, 'courses' => $c, 'past' => $past] = twinWorld();
    foreach ([0, 2, 3] as $i) {
        StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[$i]->id, 'semester_id' => $past->id, 'status' => 'failed', 'grade' => 40]);
    }
    $this->artisan('twin:build')->assertSuccessful();

    Sanctum::actingAs($student);
    $this->getJson('/api/v1/me/twin')->assertJsonPath('data.action', 'recovery_plan');
});

it('gives the advisor a short priority list led by unseen alerts, skipping recently contacted students', function () {
    ['student' => $student] = twinWorld();
    $advisor = advisor();
    User::query()->where('role', 'student')->update(['advisor_id' => $advisor->id]);
    $this->artisan('twin:build')->assertSuccessful();
    $other = User::query()->where('role', 'student')->where('id', '<>', $student->id)->first();
    DB::table('advisor_alerts')->insert(['advisor_id' => $advisor->id, 'student_id' => $other->id, 'type' => 'costly_drop', 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs($advisor);
    $priorities = $this->getJson('/api/v1/me/advisor')->json('data.priorities');
    expect($priorities)->toHaveCount(5)
        ->and($priorities[0]['id'])->toBe($other->id)
        ->and($priorities[0]['reasons'][0]['code'])->toBe('alert');

    $this->postJson("/api/v1/me/advisor/students/{$other->id}/notes", ['body' => 'Met today.', 'contacted' => true])->assertCreated();
    expect(collect($this->getJson('/api/v1/me/advisor')->json('data.priorities'))->pluck('id'))->not->toContain($other->id);
});

it('hands out a demo student of the demo advisor with a load just above the minimum', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $advisor = advisor();
    User::query()->where('role', 'student')->update(['advisor_id' => $advisor->id]);
    foreach ([0, 1, 2, 3] as $i) {
        StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[$i]->id, 'semester_id' => $now->id, 'status' => 'current_course']);
    }
    $this->artisan('twin:build')->assertSuccessful();

    config(['academic.demo.enabled' => false]);
    $this->getJson('/api/v1/demo-student')->assertNotFound();

    config(['academic.demo.enabled' => true]);
    $this->getJson('/api/v1/demo-student')->assertOk()
        ->assertJsonPath('data.email', $student->email) // the only one with 12 hours registered
        ->assertJsonPath('data.password', config('academic.demo.password'));
});

it('leads the drop dialog with the course\'s own numbers when enough students dropped and kept it', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = twinWorld();
    $this->artisan('twin:build')->assertSuccessful();
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $c[1]->id, 'semester_id' => $now->id, 'status' => 'current_course']);

    Sanctum::actingAs($student);
    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[1]->id)
        ->assertJsonPath('data.headline.source', 'course')
        ->assertJsonPath('data.headline.n_with', 20)   // 20 classmates dropped this course
        ->assertJsonPath('data.headline.n_without', 20);

    // Too few students for the course → fall back to the general numbers, labelled as such.
    config(['twin.min_cohort' => 30]);
    $this->getJson('/api/v1/me/twin/decision?action=drop&course='.$c[1]->id)->assertJsonPath('data.headline', null);
});

it('marks only about the top 10% of an advisor\'s students as critical', function () {
    twinWorld();
    $advisor = advisor();
    User::query()->where('role', 'student')->update(['advisor_id' => $advisor->id]);
    $this->artisan('twin:build')->assertSuccessful();

    Sanctum::actingAs($advisor);
    $critical = $this->getJson('/api/v1/me/advisor')->json('data.counts.critical');
    $page = $this->getJson('/api/v1/me/advisor/students?filter=risk')->assertOk();

    expect($critical)->toBeGreaterThanOrEqual(5)        // ceil(41 × 10%)
        ->and($critical)->toBeLessThan(41)
        ->and($page->json('meta.total'))->toBe($critical)
        ->and(collect($page->json('data'))->pluck('tier')->unique()->all())->toBe(['critical']);
});

it('summarises the advisor\'s last 7 days', function () {
    ['student' => $student, 'courses' => $c] = academicWorld();
    $advisor = advisor();
    $student->forceFill(['advisor_id' => $advisor->id])->save();
    $now = now();
    DB::table('advisor_alerts')->insert([
        ['advisor_id' => $advisor->id, 'student_id' => $student->id, 'type' => 'costly_drop', 'created_at' => $now, 'updated_at' => $now],
        ['advisor_id' => $advisor->id, 'student_id' => $student->id, 'type' => 'costly_drop', 'created_at' => $now->copy()->subDays(10), 'updated_at' => $now],
    ]);
    DB::table('advisor_notes')->insert(['advisor_id' => $advisor->id, 'student_id' => $student->id, 'body' => 'x', 'contacted' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('twin_decision_views')->insert([
        ['student_id' => $student->id, 'course_id' => $c[0]->id, 'dropped' => false, 'created_at' => $now, 'updated_at' => $now],
        ['student_id' => $student->id, 'course_id' => $c[1]->id, 'dropped' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);

    Sanctum::actingAs($advisor);
    $this->getJson('/api/v1/me/advisor')->assertOk()
        ->assertJsonPath('data.week.alerts', 1)      // the 10-day-old one is outside the week
        ->assertJsonPath('data.week.contacted', 1)
        ->assertJsonPath('data.week.views', 2)
        ->assertJsonPath('data.week.kept', 1);
});

it('offers an on-track demo student as well', function () {
    ['student' => $student, 'courses' => $c, 'semester' => $now] = academicWorld();
    DB::table('twin_student_status')->insert(['student_id' => $student->id, 'term_no' => 3, 'gpa' => 3.8, 'gpa_band' => 'g5', 'lane' => 'on', 'gap_hours' => 0, 'failed_pending' => 0, 'registered_hours' => 15, 'risk' => 0.03]);
    config(['academic.demo.enabled' => true]);

    $this->getJson('/api/v1/demo-student?persona=on_track')->assertOk()->assertJsonPath('data.email', $student->email);
});

it('hands out a random demo student, only from the demo advisor', function () {
    ['courses' => $c, 'semester' => $now] = twinWorld();
    $advisor = advisor();
    $elsewhere = advisor();
    $students = User::query()->where('role', 'student')->get();
    User::query()->where('role', 'student')->update(['advisor_id' => $advisor->id]);
    // Give every student 12 hours this term (the sweet spot), then move one to another advisor.
    foreach ($students as $s) {
        foreach ([0, 1, 2, 3] as $i) {
            StudentAcademicRecord::create(['student_id' => $s->id, 'course_id' => $c[$i]->id, 'semester_id' => $now->id, 'status' => 'current_course']);
        }
    }
    $outsider = $students->last();
    $outsider->forceFill(['advisor_id' => $elsewhere->id])->save();
    $this->artisan('twin:build')->assertSuccessful();
    config(['academic.demo.enabled' => true, 'academic.demo.advisor_email' => $advisor->email]);

    $seen = collect(range(1, 15))->map(fn () => $this->getJson('/api/v1/demo-student')->assertOk()->json('data.email'))->unique();

    expect($seen->count())->toBeGreaterThan(1)
        ->and($seen)->not->toContain($outsider->email);
});
