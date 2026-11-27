<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'program',
        'college',
        'major',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function students(): HasMany
    {
        return $this->hasMany(User::class, 'academic_plan_id');
    }

    public function planCourses(): HasMany
    {
        return $this->hasMany(AcademicPlanCourse::class, 'academic_plan_id');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'academic_plan_courses')
            ->withPivot(['suggested_semester'])
            ->withTimestamps();
    }
}
