<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Student ↔ advisor messages. A conversation always belongs to one student and
 * the advisor currently assigned to them; each side only ever sees its own threads.
 */
class MessagingService
{
    public const SENDER_ADVISOR = 'advisor';

    public const SENDER_STUDENT = 'student';

    /**
     * The advisor writes to one of their students. Counts as contact: opens alerts are
     * closed and a private note records it (so "students contacted" stays accurate).
     */
    public function sendFromAdvisor(User $advisor, User $student, string $body, ?int $alertId = null): int
    {
        return DB::transaction(function () use ($advisor, $student, $body, $alertId) {
            $id = DB::table('advisor_messages')->insertGetId([
                'advisor_id' => $advisor->id,
                'student_id' => $student->id,
                'sender' => self::SENDER_ADVISOR,
                'body' => trim($body),
                'alert_id' => $alertId && DB::table('advisor_alerts')->where('id', $alertId)->where('advisor_id', $advisor->id)->exists() ? $alertId : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('advisor_notes')->insert([
                'advisor_id' => $advisor->id,
                'student_id' => $student->id,
                'body' => __('advising.message_sent_note'),
                'contacted' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('advisor_alerts')->where('advisor_id', $advisor->id)->where('student_id', $student->id)
                ->whereNull('seen_at')->update(['seen_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    /** The student replies to their current advisor. */
    public function sendFromStudent(User $student, string $body): int
    {
        return DB::table('advisor_messages')->insertGetId([
            'advisor_id' => $student->advisor_id,
            'student_id' => $student->id,
            'sender' => self::SENDER_STUDENT,
            'body' => trim($body),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Messages between this student and this advisor, oldest first (latest 100). */
    public function thread(int $advisorId, int $studentId): array
    {
        return DB::table('advisor_messages')
            ->where('advisor_id', $advisorId)->where('student_id', $studentId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)
            ->get(['id', 'sender', 'body', 'read_at', 'created_at'])
            ->reverse()->values()
            ->map(fn ($m) => ['id' => $m->id, 'sender' => $m->sender, 'body' => $m->body, 'read_at' => $m->read_at, 'created_at' => $m->created_at])
            ->all();
    }

    /** Mark what the other side sent as read by `$reader` (advisor or student). */
    public function markRead(int $advisorId, int $studentId, string $reader): void
    {
        DB::table('advisor_messages')
            ->where('advisor_id', $advisorId)->where('student_id', $studentId)
            ->where('sender', $reader === self::SENDER_ADVISOR ? self::SENDER_STUDENT : self::SENDER_ADVISOR)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function unreadForStudent(User $student): int
    {
        return $student->advisor_id ? DB::table('advisor_messages')->where('student_id', $student->id)
            ->where('advisor_id', $student->advisor_id)->where('sender', self::SENDER_ADVISOR)->whereNull('read_at')->count() : 0;
    }

    /** Students who replied and the advisor hasn't read yet. */
    public function unreadRepliesForAdvisor(User $advisor): array
    {
        return DB::table('advisor_messages as m')->join('users as u', 'u.id', '=', 'm.student_id')
            ->where('m.advisor_id', $advisor->id)->where('m.sender', self::SENDER_STUDENT)->whereNull('m.read_at')
            ->groupBy('m.student_id', 'u.full_name')
            ->selectRaw('m.student_id as id, u.full_name, COUNT(*) as count, MAX(m.created_at) as last_at')
            ->orderByDesc('last_at')->limit(20)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'full_name' => $r->full_name, 'count' => (int) $r->count, 'last_at' => $r->last_at])
            ->all();
    }
}
