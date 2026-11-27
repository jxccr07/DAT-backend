<?php

use App\Models\StudentAcademicRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->world = academicWorld();
    Sanctum::actingAs($this->world['student']);
});

it('lists available courses excluding passed ones', function () {
    ['student' => $student, 'courses' => $courses, 'past' => $past] = $this->world;
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $courses[0]->id,
        'semester_id' => $past->id, 'status' => 'passed', 'grade' => 90]);

    $response = $this->getJson('/api/v1/me/registration')->assertOk();

    expect($response->json('data.available'))->toHaveCount(6)
        ->and(collect($response->json('data.available'))->pluck('code'))->not->toContain('CS001')
        ->and($response->json('data.window_open'))->toBeTrue()
        ->and($response->json('data.limits.max_hours'))->toBe(18);
});

it('registers a course and persists it in the database', function () {
    ['student' => $student, 'courses' => $courses, 'semester' => $semester] = $this->world;

    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $courses[1]->id])
        ->assertCreated()
        ->assertJsonPath('data.code', 'CS002');

    $this->assertDatabaseHas('student_academic_records', [
        'student_id' => $student->id, 'course_id' => $courses[1]->id,
        'semester_id' => $semester->id, 'status' => 'current_course',
    ]);

    expect($this->getJson('/api/v1/me/registration')->json('data.registered_hours'))->toBe(3);
});

it('rejects duplicate registration', function () {
    $id = $this->world['courses'][0]->id;
    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $id])->assertCreated();
    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $id])
        ->assertStatus(422)->assertJsonPath('code', 'course_already_registered');
});

it('rejects a course that is already passed', function () {
    ['student' => $student, 'courses' => $courses, 'past' => $past] = $this->world;
    StudentAcademicRecord::create(['student_id' => $student->id, 'course_id' => $courses[0]->id,
        'semester_id' => $past->id, 'status' => 'passed', 'grade' => 80]);

    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $courses[0]->id])
        ->assertStatus(422)->assertJsonPath('code', 'course_already_passed');
});

it('rejects a course outside the student plan', function () {
    $this->postJson('/api/v1/me/registration/courses', ['course_id' => 999])
        ->assertStatus(422)->assertJsonPath('code', 'course_not_in_plan');
});

it('enforces the maximum credit hours', function () {
    config(['academic.registration.max_hours' => 6]);
    $courses = $this->world['courses'];

    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $courses[0]->id])->assertCreated();
    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $courses[1]->id])->assertCreated();
    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $courses[2]->id])
        ->assertStatus(422)->assertJsonPath('code', 'max_hours_exceeded');
});

it('drops a registered course', function () {
    $id = $this->world['courses'][0]->id;
    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $id])->assertCreated();

    $this->deleteJson("/api/v1/me/registration/courses/{$id}")->assertNoContent();
    $this->assertDatabaseMissing('student_academic_records', ['course_id' => $id, 'status' => 'current_course']);

    $this->deleteJson("/api/v1/me/registration/courses/{$id}")
        ->assertNotFound()->assertJsonPath('code', 'course_not_registered');
});

it('blocks changes outside the registration window when enforced', function () {
    config(['academic.registration.enforce_window' => true]);
    $this->world['semester']->update(['registration_ends_at' => now()->subHour()]);

    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $this->world['courses'][0]->id])
        ->assertForbidden()->assertJsonPath('code', 'registration_closed');
});

it('only touches the authenticated student records', function () {
    $other = \App\Models\User::factory()->create(['academic_plan_id' => $this->world['plan']->id]);
    $id = $this->world['courses'][0]->id;
    StudentAcademicRecord::create(['student_id' => $other->id, 'course_id' => $id,
        'semester_id' => $this->world['semester']->id, 'status' => 'current_course']);

    $this->deleteJson("/api/v1/me/registration/courses/{$id}")->assertNotFound();
    $this->assertDatabaseHas('student_academic_records', ['student_id' => $other->id, 'course_id' => $id]);
});

it('includes study year in the profile', function () {
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.study_year', 3);
});
