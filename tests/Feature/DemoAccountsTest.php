<?php

use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Support\DemoAccounts;
use App\Twin\TwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function demoPool(): array
{
    Storage::fake('local'); // the demo commands write their baseline files here: never the real storage
    $demo = User::factory()->create(['role' => User::ROLE_ADVISOR, 'email' => 'demo.advisor@example.com']);
    $other = User::factory()->create(['role' => User::ROLE_ADVISOR]);
    $mine = User::factory()->create(['advisor_id' => $demo->id]);
    $theirs = User::factory()->create(['advisor_id' => $other->id]);

    config(['academic.demo.password' => 'a-long-random-demo-secret', 'academic.demo.advisor_email' => $demo->email]);

    return compact('demo', 'other', 'mine', 'theirs');
}

function canSignIn(User $user, string $password): bool
{
    return test()->postJson('/api/v1/me/login', ['email' => $user->email, 'password' => $password])->isOk();
}

it('locks every account except the demo advisor and their students, and signs everyone out', function () {
    ['demo' => $demo, 'other' => $other, 'mine' => $mine, 'theirs' => $theirs] = demoPool();
    $theirs->createToken('old');

    $this->artisan('demo:secure-accounts --force')->assertSuccessful();

    expect(canSignIn($demo, 'a-long-random-demo-secret'))->toBeTrue()
        ->and(canSignIn($mine, 'a-long-random-demo-secret'))->toBeTrue()
        ->and(canSignIn($other, 'a-long-random-demo-secret'))->toBeFalse()
        ->and(canSignIn($theirs, 'a-long-random-demo-secret'))->toBeFalse()
        ->and(canSignIn($theirs, 'password'))->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('name', 'old')->count())->toBe(0);
});

it('refuses to run with a weak demo password or without a pinned advisor', function () {
    demoPool();

    config(['academic.demo.password' => 'password']);
    $this->artisan('demo:secure-accounts --force')->assertFailed();

    config(['academic.demo.password' => 'a-long-random-demo-secret', 'academic.demo.advisor_email' => null]);
    $this->artisan('demo:secure-accounts --force')->assertFailed();

    config(['academic.demo.advisor_email' => 'nobody@example.com']);
    $this->artisan('demo:secure-accounts --force')->assertFailed();
});

it('only ever hands out the pinned demo advisor, even when another has more alerts', function () {
    ['demo' => $demo, 'other' => $other, 'theirs' => $theirs] = demoPool();
    config(['academic.demo.enabled' => true]);
    DB::table('advisor_alerts')->insert(['advisor_id' => $other->id, 'student_id' => $theirs->id, 'type' => 'costly_drop', 'created_at' => now(), 'updated_at' => now()]);

    $this->getJson('/api/v1/demo-advisor')->assertOk()->assertJsonPath('data.email', $demo->email);

    // A pinned advisor that no longer exists: hand out nothing rather than a locked account.
    config(['academic.demo.advisor_email' => 'gone@example.com']);
    $this->getJson('/api/v1/demo-advisor')->assertNotFound();
    $this->getJson('/api/v1/demo-student')->assertNotFound();
});

function row(string $table, array $values): int
{
    return DB::table($table)->insertGetId($values + ['updated_at' => now()]);
}

it('tidies visitor content after 10 minutes but keeps the prepared scenario, unread again', function () {
    Storage::fake('local');
    ['demo' => $demo, 'other' => $other, 'mine' => $mine, 'theirs' => $theirs] = demoPool();
    config(['academic.demo.enabled' => true]);

    // The prepared scenario, before the demo went live.
    $scenario = row('advisor_alerts', ['advisor_id' => $demo->id, 'student_id' => $mine->id, 'type' => 'costly_drop', 'created_at' => now()]);
    $this->travel(1)->minutes();
    DemoAccounts::markBaseline();
    $this->travel(1)->minutes();

    // Visitors: an old message, a fresh one, a note, a meeting request, and the scenario alert marked read.
    $old = row('advisor_messages', ['advisor_id' => $demo->id, 'student_id' => $mine->id, 'sender' => 'student', 'body' => 'rude', 'created_at' => now()]);
    row('advisor_notes', ['advisor_id' => $demo->id, 'student_id' => $mine->id, 'body' => 'x', 'created_at' => now()]);
    row('advisor_alerts', ['advisor_id' => $demo->id, 'student_id' => $mine->id, 'type' => 'meeting_request', 'created_at' => now()]);
    DB::table('advisor_alerts')->where('id', $scenario)->update(['seen_at' => now()]);
    // Another advisor's content is never touched.
    $foreign = row('advisor_messages', ['advisor_id' => $other->id, 'student_id' => $theirs->id, 'sender' => 'student', 'body' => 'hi', 'created_at' => now()]);

    $this->travel(11)->minutes();
    $fresh = row('advisor_messages', ['advisor_id' => $demo->id, 'student_id' => $mine->id, 'sender' => 'advisor', 'body' => 'just now', 'created_at' => now()]);

    $this->artisan('demo:tidy')->assertSuccessful();

    expect(DB::table('advisor_messages')->pluck('id')->all())->toEqualCanonicalizing([$fresh, $foreign])
        ->and(DB::table('advisor_notes')->count())->toBe(0)
        ->and(DB::table('advisor_alerts')->pluck('id')->all())->toBe([$scenario])
        ->and(DB::table('advisor_alerts')->where('id', $scenario)->value('seen_at'))->toBeNull();
});

it('does nothing outside the pinned public demo', function () {
    Storage::fake('local');
    ['demo' => $demo, 'mine' => $mine] = demoPool();
    config(['academic.demo.enabled' => false]);
    DemoAccounts::markBaseline();
    $this->travel(1)->minutes();
    row('advisor_messages', ['advisor_id' => $demo->id, 'student_id' => $mine->id, 'sender' => 'student', 'body' => 'keep', 'created_at' => now()]);
    $this->travel(30)->minutes();

    $this->artisan('demo:tidy')->assertSuccessful();

    expect(DB::table('advisor_messages')->count())->toBe(1);
});

/** A demo-pool student (and one outside the pool) registered for two courses this term. */
function demoRegistrations(): array
{
    Storage::fake('local');
    $pool = demoPool();
    config(['academic.demo.enabled' => true]);
    $world = academicWorld();
    $student = $world['student'];
    $student->forceFill(['advisor_id' => $pool['demo']->id])->save();
    $pool['theirs']->forceFill(['academic_plan_id' => $world['plan']->id])->save();

    foreach ([$student, $pool['theirs']] as $who) {
        foreach ([0, 1] as $i) {
            StudentAcademicRecord::create(['student_id' => $who->id, 'course_id' => $world['courses'][$i]->id, 'semester_id' => $world['semester']->id, 'status' => 'current_course']);
        }
    }

    return $world + $pool;
}

function registered(User $student): array
{
    return DB::table('student_academic_records')->where('student_id', $student->id)
        ->where('status', 'current_course')->orderBy('course_id')->pluck('course_id')->all();
}

it('puts a demo student\'s registrations back once they have been quiet, and refreshes their twin status', function () {
    ['student' => $student, 'courses' => $c, 'theirs' => $theirs] = demoRegistrations();
    $this->artisan('demo:secure-accounts --force')->assertSuccessful();

    // A visitor drops one course and adds another; someone outside the pool changes theirs too.
    Sanctum::actingAs($student);
    $this->deleteJson("/api/v1/me/registration/courses/{$c[0]->id}")->assertNoContent();
    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $c[2]->id])->assertCreated();
    DB::table('student_academic_records')->where('student_id', $theirs->id)->where('course_id', $c[0]->id)->delete();

    $this->travel(11)->minutes();
    $twin = Mockery::spy(TwinService::class);
    $this->app->instance(TwinService::class, $twin);

    $this->artisan('demo:tidy')->assertSuccessful();

    expect(registered($student))->toBe([$c[0]->id, $c[1]->id])
        ->and(registered($theirs))->toBe([$c[1]->id]);
    $twin->shouldHaveReceived('refreshStatus')->once()->withArgs(fn (User $u) => $u->id === $student->id);
});

it('never undoes a demo student\'s registration change in the middle of their walkthrough', function () {
    ['student' => $student, 'courses' => $c] = demoRegistrations();
    $this->artisan('demo:secure-accounts --force')->assertSuccessful();
    Sanctum::actingAs($student);

    // Only a drop, long after the rows were made: nothing left in the table says when it happened, the touch file does.
    $this->travel(20)->minutes();
    $this->deleteJson("/api/v1/me/registration/courses/{$c[0]->id}")->assertNoContent();

    $this->travel(5)->minutes();
    $this->artisan('demo:tidy')->assertSuccessful();
    expect(registered($student))->toBe([$c[1]->id]);

    $this->travel(6)->minutes();
    $this->artisan('demo:tidy')->assertSuccessful();
    expect(registered($student))->toBe([$c[0]->id, $c[1]->id]);
});

it('takes the registration snapshot on the first tidy when there is none yet', function () {
    ['student' => $student, 'courses' => $c] = demoRegistrations();

    $this->artisan('demo:tidy')->assertSuccessful();
    expect(Storage::disk('local')->exists('demo-registrations.json'))->toBeTrue();

    DB::table('student_academic_records')->where('student_id', $student->id)->where('course_id', $c[1]->id)->delete();
    $this->travel(11)->minutes();
    $this->artisan('demo:tidy')->assertSuccessful();

    expect(registered($student))->toBe([$c[0]->id, $c[1]->id]);
});

it('leaves registrations alone outside the pinned public demo', function () {
    ['student' => $student, 'courses' => $c] = demoRegistrations();
    $this->artisan('demo:secure-accounts --force')->assertSuccessful();
    config(['academic.demo.enabled' => false]);
    Sanctum::actingAs($student);
    $this->deleteJson("/api/v1/me/registration/courses/{$c[0]->id}")->assertNoContent();

    $this->travel(30)->minutes();
    $this->artisan('demo:tidy')->assertSuccessful();

    expect(registered($student))->toBe([$c[1]->id])
        ->and(Storage::disk('local')->exists("demo-registration-touches/{$student->id}"))->toBeFalse();
});
