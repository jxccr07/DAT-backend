<?php

use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Twin\TwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** The twin world, with the main student registered in the course classmates paid for dropping. */
function deadlineWorld(): array
{
    $world = twinWorld();
    config(['twin.delayed_gap_hours' => 6, 'academic.registration.drop_deadline' => now()->addDays(9)->toDateString()]);
    test()->artisan('twin:build')->assertSuccessful();

    $advisor = User::factory()->create(['role' => User::ROLE_ADVISOR]);
    $world['student']->forceFill(['advisor_id' => $advisor->id])->save();
    foreach ([1, 2] as $i) {
        StudentAcademicRecord::create(['student_id' => $world['student']->id, 'course_id' => $world['courses'][$i]->id, 'semester_id' => $world['semester']->id, 'status' => 'current_course']);
    }
    app(TwinService::class)->refreshStatus($world['student']);

    return $world + ['advisor' => $advisor];
}

it('lists students whose drop looks costly, with the reason and days left', function () {
    ['advisor' => $advisor, 'student' => $student, 'courses' => $c] = deadlineWorld();
    Sanctum::actingAs($advisor);

    $data = $this->getJson('/api/v1/me/advisor/before-deadline')->assertOk()->json('data');

    expect($data['days_left'])->toBe(9)
        ->and($data['total'])->toBe(1)
        ->and($data['students'][0]['id'])->toBe($student->id)
        ->and($data['students'][0]['course']['code'])->toBe($c[1]->code)
        ->and(collect($data['students'][0]['reasons'])->pluck('code')->all())->toContain('drop_cost')
        // Already under the minimum before dropping: that belongs to the weekly list, not this decision.
        ->and(collect($data['students'][0]['reasons'])->pluck('code')->all())->not->toContain('below_min')
        ->and($data['students'][0]['requested'])->toBeFalse();
});

it('leaves out students contacted recently, until they ask for a meeting', function () {
    ['advisor' => $advisor, 'student' => $student, 'courses' => $c] = deadlineWorld();

    Sanctum::actingAs($advisor);
    $this->postJson("/api/v1/me/advisor/students/{$student->id}/notes", ['body' => 'Spoke after class', 'contacted' => true])->assertCreated();
    $this->getJson('/api/v1/me/advisor/before-deadline')->assertJsonPath('data.total', 0);

    $this->travel(1)->minutes();
    Sanctum::actingAs($student);
    $this->postJson('/api/v1/me/advisor-request', ['course' => $c[2]->id])->assertCreated();

    Sanctum::actingAs($advisor);
    $row = $this->getJson('/api/v1/me/advisor/before-deadline')->json('data.students.0');
    expect($row['requested'])->toBeTrue()
        ->and($row['reasons'][0]['code'])->toBe('requested');
});

it('records a meeting request only for the student\'s own advisor and courses, once', function () {
    ['advisor' => $advisor, 'student' => $student, 'courses' => $c] = deadlineWorld();
    Sanctum::actingAs($student);

    $this->postJson('/api/v1/me/advisor-request', ['course' => $c[6]->id])->assertCreated(); // not registered: kept without a course
    $this->postJson('/api/v1/me/advisor-request', ['course' => $c[1]->id])->assertCreated();

    $requests = DB::table('advisor_alerts')->where('type', 'meeting_request')->get();
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->advisor_id)->toBe($advisor->id)
        ->and($requests[0]->course_id)->toBe($c[1]->id);

    // No advisor, nothing to send.
    $loner = User::factory()->create();
    Sanctum::actingAs($loner);
    $this->postJson('/api/v1/me/advisor-request')->assertUnprocessable();
});

it('keeps the list for advisors and caps it at a day\'s work', function () {
    ['advisor' => $advisor, 'student' => $student] = deadlineWorld();
    Sanctum::actingAs($student);
    $this->getJson('/api/v1/me/advisor/before-deadline')->assertForbidden();

    Sanctum::actingAs($advisor);
    expect($this->getJson('/api/v1/me/advisor/before-deadline')->json('data.daily_cap'))->toBe(\App\Services\DropDeadlineService::DAILY_CAP);
});

it('stays empty once the deadline has passed', function () {
    ['advisor' => $advisor] = deadlineWorld();
    config(['academic.registration.drop_deadline' => now()->subDay()->toDateString()]);
    Sanctum::actingAs($advisor);

    $this->getJson('/api/v1/me/advisor/before-deadline')->assertOk()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.days_left', -1);
});
