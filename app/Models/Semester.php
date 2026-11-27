<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Semester extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'academic_year',
        'semester',
        'registration_starts_at',
        'registration_ends_at',
        'starts_at',
        'ends_at',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'registration_starts_at' => 'datetime',
            'registration_ends_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function academicRecords(): HasMany
    {
        return $this->hasMany(StudentAcademicRecord::class, 'semester_id');
    }
}
