<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-portal conversation between a student and their academic advisor.
 * Separate from advisor_notes on purpose: notes are the advisor's private record,
 * messages are what the student sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('sender', 8);                         // advisor | student
            $table->text('body');
            $table->foreignId('alert_id')->nullable()->constrained('advisor_alerts')->nullOnDelete();
            $table->timestamp('read_at')->nullable();            // read by the other side
            $table->timestamps();
            $table->index(['student_id', 'created_at']);
            $table->index(['advisor_id', 'sender', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_messages');
    }
};
