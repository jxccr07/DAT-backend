<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('academic_year');
            $table->enum('semester', ['first', 'second', 'summer']);
            $table->timestamp('registration_starts_at')->nullable();
            $table->timestamp('registration_ends_at')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['academic_year', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semesters');
    }
};
