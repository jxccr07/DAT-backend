<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function messagingWorld(): array
{
    ['student' => $student] = academicWorld();
    $advisor = User::factory()->create(['role' => User::ROLE_ADVISOR, 'full_name' => 'د. محمد القحطاني']);
    $student->forceFill(['advisor_id' => $advisor->id])->save();

    return [$student->fresh(), $advisor];
}

it('delivers an advisor message to the student, who sees it as unread, reads it and replies', function () {
    [$student, $advisor] = messagingWorld();
    $alert = DB::table('advisor_alerts')->insertGetId(['advisor_id' => $advisor->id, 'student_id' => $student->id, 'type' => 'costly_drop', 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs($advisor);
    $this->postJson("/api/v1/me/advisor/students/{$student->id}/messages", ['body' => 'Let\'s meet this week.', 'alert_id' => $alert])->assertCreated();

    // Sending counts as contact: the alert is closed and a private note records it.
    expect(DB::table('advisor_alerts')->where('id', $alert)->value('seen_at'))->not->toBeNull()
        ->and(DB::table('advisor_notes')->where('contacted', true)->count())->toBe(1);

    Sanctum::actingAs($student);
    $this->getJson('/api/v1/me')->assertJsonPath('data.unread_messages', 1);
    $this->getJson('/api/v1/me/messages')->assertOk()
        ->assertJsonPath('data.advisor.full_name', 'د. محمد القحطاني')
        ->assertJsonPath('data.advisor.email', $advisor->email)
        ->assertJsonPath('data.messages.0.sender', 'advisor')
        ->assertJsonPath('data.messages.0.body', 'Let\'s meet this week.');
    $this->getJson('/api/v1/me')->assertJsonPath('data.unread_messages', 0);

    $this->postJson('/api/v1/me/messages', ['body' => 'Thursday works.'])->assertCreated();

    Sanctum::actingAs($advisor);
    $this->getJson('/api/v1/me/advisor')->assertJsonPath('data.unread_replies.0.id', $student->id)
        ->assertJsonPath('data.unread_replies.0.count', 1);
    $file = $this->getJson("/api/v1/me/advisor/students/{$student->id}")->assertOk();
    expect(collect($file->json('data.messages'))->pluck('sender')->all())->toBe(['advisor', 'student']);
    $this->getJson('/api/v1/me/advisor')->assertJsonPath('data.unread_replies', []);
});

it('puts a student who wrote back at the top of the priorities, even if contacted recently', function () {
    [$student, $advisor] = messagingWorld();
    DB::table('twin_student_status')->insert(['student_id' => $student->id, 'term_no' => 1, 'gpa_band' => 'new', 'lane' => 'on', 'gap_hours' => 0, 'failed_pending' => 0, 'registered_hours' => 15, 'risk' => 0.1]);

    Sanctum::actingAs($advisor);
    $this->postJson("/api/v1/me/advisor/students/{$student->id}/messages", ['body' => 'Hi'])->assertCreated();
    expect($this->getJson('/api/v1/me/advisor')->json('data.priorities'))->toBe([]); // contacted → off the list

    Sanctum::actingAs($student);
    $this->postJson('/api/v1/me/messages', ['body' => 'I have a question'])->assertCreated();

    Sanctum::actingAs($advisor);
    $this->getJson('/api/v1/me/advisor')->assertJsonPath('data.priorities.0.id', $student->id)
        ->assertJsonPath('data.priorities.0.reasons.0.code', 'reply');
});

it('keeps conversations private to the student and their own advisor', function () {
    [$student, $advisor] = messagingWorld();
    $other = User::factory()->create(['role' => User::ROLE_ADVISOR]);
    $stranger = User::factory()->create();

    Sanctum::actingAs($other);
    $this->postJson("/api/v1/me/advisor/students/{$student->id}/messages", ['body' => 'Hi'])->assertNotFound();

    Sanctum::actingAs($advisor);
    $this->postJson("/api/v1/me/advisor/students/{$student->id}/messages", ['body' => 'Private'])->assertCreated();

    Sanctum::actingAs($stranger);
    $this->getJson('/api/v1/me/messages')->assertOk()->assertJsonPath('data.messages', []);
    $this->postJson('/api/v1/me/messages', ['body' => 'Hello?'])->assertStatus(422); // no advisor

    Sanctum::actingAs($advisor);
    $this->getJson('/api/v1/me/messages')->assertForbidden(); // advisors use their own endpoints
    $this->postJson("/api/v1/me/advisor/students/{$student->id}/messages", ['body' => ''])->assertUnprocessable();
});
