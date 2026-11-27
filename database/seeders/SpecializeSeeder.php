<?php

namespace Database\Seeders;

use App\Models\Specialize;
use Database\Seeders\Support\UniversityCatalog;
use Illuminate\Database\Seeder;

class SpecializeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (UniversityCatalog::allSpecializations() as $specializationName) {
            Specialize::create([
                'name' => $specializationName,
            ]);
        }
    }
}
