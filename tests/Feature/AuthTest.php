<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('logs in with valid credentials and returns an expiring token', function () {
    User::factory()->create(['email' => '200000001@example.com']);

    $this->postJson('/api/v1/me/login', ['email' => '200000001@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'expires_at', 'user' => ['id', 'full_name', 'email']]])
        ->assertJsonMissingPath('data.user.password');
});

it('rejects wrong password and unknown email with the same response', function () {
    User::factory()->create(['email' => '200000001@example.com']);

    $wrong = $this->postJson('/api/v1/me/login', ['email' => '200000001@example.com', 'password' => 'nope']);
    $unknown = $this->postJson('/api/v1/me/login', ['email' => 'ghost@example.com', 'password' => 'nope']);

    $wrong->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
    expect($unknown->json())->toBe($wrong->json());
});

it('validates login input', function () {
    $this->postJson('/api/v1/me/login', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('throttles brute-force login attempts per account', function () {
    User::factory()->create(['email' => '200000001@example.com']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/me/login', ['email' => '200000001@example.com', 'password' => 'bad'])->assertStatus(422);
    }

    $this->postJson('/api/v1/me/login', ['email' => '200000001@example.com', 'password' => 'password'])
        ->assertStatus(429);
});

it('returns localized messages from Accept-Language', function () {
    $this->withHeader('Accept-Language', 'ar')
        ->postJson('/api/v1/me/login', ['email' => 'x@example.com', 'password' => 'bad'])
        ->assertJsonPath('message', 'البريد الإلكتروني أو كلمة المرور غير صحيحة.');
});

it('revokes the token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/me/logout')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('requires authentication for every student endpoint', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/me'],
    ['GET', '/api/v1/me/schedule'],
    ['GET', '/api/v1/me/academic/plan'],
    ['GET', '/api/v1/me/academic/grades'],
    ['GET', '/api/v1/me/academic/term'],
    ['GET', '/api/v1/me/registration'],
    ['POST', '/api/v1/me/registration/courses'],
    ['DELETE', '/api/v1/me/registration/courses/1'],
]);

it('no longer exposes the public student listing or lookup', function (string $uri) {
    $this->getJson($uri)->assertNotFound();
})->with(['/api/v1/get/student', '/api/v1/get/student/info?student_id=%25']);

it('answers a non-string email with a validation error and still counts it against the IP limit', function (mixed $email) {
    $this->postJson('/api/v1/me/login', ['email' => $email, 'password' => 'x'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // 30 per minute per IP: the malformed request above was the first of them.
    foreach (range(1, 29) as $i) {
        $this->postJson('/api/v1/me/login', ['email' => "user{$i}@example.com", 'password' => 'x'])->assertStatus(422);
    }
    $this->postJson('/api/v1/me/login', ['email' => 'last@example.com', 'password' => 'x'])->assertStatus(429);
})->with([
    'list' => [['a@b.c']],
    'object' => [['x' => 'a@b.c']],
    'number' => [12345],
]);

it('checks a password hash even when the email is unknown, so both paths take about as long', function () {
    Hash::spy();

    $this->postJson('/api/v1/me/login', ['email' => 'ghost@example.com', 'password' => 'nope'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');

    Hash::shouldHaveReceived('check')->once()
        ->withArgs(fn ($value, $hash) => $value === 'nope' && str_starts_with((string) $hash, '$2y$12$'));
});
