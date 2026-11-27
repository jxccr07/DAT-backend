<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    public const ROLE_STUDENT = 'student';

    public const ROLE_ADVISOR = 'advisor';

    protected $fillable = [
        'full_name',
        'email',
        'phone_number',
        'email_verified_at',
        'password',
        'academic_plan_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function academicPlan(): BelongsTo
    {
        return $this->belongsTo(AcademicPlan::class, 'academic_plan_id');
    }

    public function academicRecords(): HasMany
    {
        return $this->hasMany(StudentAcademicRecord::class, 'student_id');
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    public function advisees(): HasMany
    {
        return $this->hasMany(User::class, 'advisor_id');
    }

    /** Academic number = the email's local part (e.g. 300000123). */
    public function studentCode(): string
    {
        return explode('@', (string) $this->email)[0];
    }

    /** Academic numbers are generated as {studyYear}{8-digit sequence}. */
    public function studyYear(): ?int
    {
        $code = $this->studentCode();

        return strlen($code) === 9 && ctype_digit($code) ? (int) $code[0] : null;
    }

    public function isAdvisor(): bool
    {
        return $this->role === self::ROLE_ADVISOR;
    }
}
