<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

$huge = '99999999999999999999';

it('answers 404 for a course id too large to be one', function () use ($huge) {
    ['student' => $student] = academicWorld();
    Sanctum::actingAs($student);

    $this->deleteJson("/api/v1/me/registration/courses/{$huge}")->assertNotFound();
});

it('answers 404 for advisor route ids too large to be one', function (string $method, string $uri) {
    Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADVISOR]));

    $this->json($method, $uri, ['body' => 'hi'])->assertNotFound();
})->with([
    ['GET', "/api/v1/me/advisor/students/{$huge}"],
    ['POST', "/api/v1/me/advisor/students/{$huge}/notes"],
    ['POST', "/api/v1/me/advisor/students/{$huge}/messages"],
    ['POST', "/api/v1/me/advisor/alerts/{$huge}/seen"],
]);

it('still accepts ids up to 18 digits', function () {
    ['student' => $student] = academicWorld();
    Sanctum::actingAs($student);

    // Reaches the controller: the course is simply not registered.
    $this->deleteJson('/api/v1/me/registration/courses/999999999999999999')
        ->assertNotFound()->assertJsonPath('code', 'course_not_registered');
});
