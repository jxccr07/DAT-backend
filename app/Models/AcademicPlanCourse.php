<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicPlanCourse extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_plan_id',
        'course_id',
        'suggested_semester',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AcademicPlan::class, 'academic_plan_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
