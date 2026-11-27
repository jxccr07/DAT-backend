<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

$studentOnly = [
    ['GET', '/api/v1/me/schedule'],
    ['GET', '/api/v1/me/academic/plan'],
    ['GET', '/api/v1/me/academic/term'],
    ['GET', '/api/v1/me/academic/grades'],
    ['GET', '/api/v1/me/registration'],
    ['POST', '/api/v1/me/registration/courses'],
    ['DELETE', '/api/v1/me/registration/courses/1'],
    ['GET', '/api/v1/me/twin'],
    ['GET', '/api/v1/me/twin/decision?action=light'],
    ['GET', '/api/v1/me/twin/timeline'],
    ['GET', '/api/v1/me/messages'],
    ['POST', '/api/v1/me/messages'],
    ['POST', '/api/v1/me/advisor-request'],
];

it('keeps student endpoints closed to advisor accounts', function (string $method, string $uri) {
    ['plan' => $plan, 'courses' => $courses] = academicWorld();
    $advisor = User::factory()->create(['role' => User::ROLE_ADVISOR, 'academic_plan_id' => $plan->id]);
    Sanctum::actingAs($advisor);

    $this->json($method, $uri, ['course_id' => $courses[0]->id, 'body' => 'hi'])->assertForbidden();

    expect(DB::table('student_academic_records')->where('student_id', $advisor->id)->count())->toBe(0)
        ->and(DB::table('twin_student_status')->where('student_id', $advisor->id)->count())->toBe(0);
})->with($studentOnly);

it('still serves the student endpoints to students', function (string $uri) {
    ['student' => $student] = academicWorld();
    Sanctum::actingAs($student);

    $this->getJson($uri)->assertOk();
})->with([
    '/api/v1/me/schedule',
    '/api/v1/me/academic/plan',
    '/api/v1/me/academic/term',
    '/api/v1/me/academic/grades',
    '/api/v1/me/registration',
    '/api/v1/me/twin',
    '/api/v1/me/twin/decision?action=light',
    '/api/v1/me/twin/timeline',
    '/api/v1/me/messages',
]);

it('lets a student register and drop a course', function () {
    ['student' => $student, 'courses' => $courses] = academicWorld();
    Sanctum::actingAs($student);

    $this->postJson('/api/v1/me/registration/courses', ['course_id' => $courses[0]->id])->assertCreated();
    $this->deleteJson("/api/v1/me/registration/courses/{$courses[0]->id}")->assertNoContent();
});

it('keeps the account, the methodology page and sign-out open to both roles', function (string $role) {
    ['plan' => $plan] = academicWorld();
    $user = User::factory()->create(['role' => $role, 'academic_plan_id' => $plan->id]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')->assertOk();
    $this->getJson('/api/v1/me/twin/meta')->assertOk();
    $this->postJson('/api/v1/me/logout')->assertOk();
})->with([User::ROLE_STUDENT, User::ROLE_ADVISOR]);
