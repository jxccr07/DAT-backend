<?php

namespace App\Http\Controllers;

use App\Support\DemoAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Demo-only: sign-in details of a student whose advisor is the demo advisor
 * (the one with the most open alerts) who has courses registered, so dropping
 * one shows the full loop: price of the decision, then the alert appearing in
 * the advisor's view. Prefers a load at or just above the minimum (a drop then
 * crosses it); otherwise the heaviest load available. Disabled unless DEMO_MODE=true.
 */
class DemoStudentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('academic.demo.enabled'), 404);

        // Only the demo advisor's students: once an advisor is pinned, every other account is locked.
        $advisorId = DemoAccounts::advisorId();
        abort_if(! $advisorId && DemoAccounts::isPinned(), 404);

        // A student who is doing well, so visitors also see the calm side of the portal.
        if ($request->query('persona') === 'on_track') {
            $email = DB::table('users as u')
                ->join('twin_student_status as s', 's.student_id', '=', 'u.id')
                ->where('u.role', 'student')
                ->when($advisorId, fn ($q) => $q->where('u.advisor_id', $advisorId))
                ->where('s.lane', 'on')->whereIn('s.gpa_band', ['g4', 'g5'])
                ->where('s.failed_pending', 0)
                ->where('s.registered_hours', '>', 0)
                // Prefer a full load when the data has one.
                ->orderByRaw('CASE WHEN s.registered_hours >= ? THEN 0 ELSE 1 END', [(int) config('academic.registration.min_hours')])
                ->inRandomOrder()->value('u.email');
            abort_unless($email, 404);

            return response()->json(['data' => ['email' => $email, 'password' => (string) config('academic.demo.password')]]);
        }

        $min = (int) config('academic.registration.min_hours');
        $candidates = DB::table('users as u')
            ->join('twin_student_status as s', 's.student_id', '=', 'u.id')
            ->when($advisorId, fn ($q) => $q->where('u.advisor_id', $advisorId))
            ->where('u.role', 'student')
            ->where('s.registered_hours', '>=', 5)
            ->whereNotExists(fn ($q) => $q->from('advisor_alerts as a')->whereColumn('a.student_id', 'u.id'));

        // A different student on each click, so visitors rarely share one: random among those whose
        // load sits just above the minimum (a drop then crosses it), else random among the rest.
        $student = (clone $candidates)->whereBetween('s.registered_hours', [$min, $min + 4])->inRandomOrder()->value('u.email')
            ?? (clone $candidates)->inRandomOrder()->value('u.email');

        abort_unless($student, 404);

        return response()->json(['data' => ['email' => $student, 'password' => (string) config('academic.demo.password')]]);
    }
}
