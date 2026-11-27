<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'specialization_id',
        'level',
        'hours',
        'has_lab',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'has_lab' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function planCourses(): HasMany
    {
        return $this->hasMany(AcademicPlanCourse::class);
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialize::class, 'specialization_id');
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(AcademicPlan::class, 'academic_plan_courses')
            ->withPivot(['suggested_semester'])
            ->withTimestamps();
    }

    public function academicRecords(): HasMany
    {
        return $this->hasMany(StudentAcademicRecord::class);
    }

}
