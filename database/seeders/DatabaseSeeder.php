<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeder order matters because courses depend on specializations and records depend on students.
        $this->call([
            SpecializeSeeder::class,
            CourseSeeder::class,
            AcademicPlanSeeder::class,
            SemesterSeeder::class,
            StudentSeeder::class,
            StudentAcademicRecordSeeder::class,
        ]);
    }
}
