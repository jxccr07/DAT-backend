<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DropDeadlineService;
use App\Services\MessagingService;
use App\Twin\TwinService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The advisor's side of the twin: their students ranked by risk, alerts when a
 * student goes ahead with a costly decision, and follow-up notes.
 * Every query is scoped to the signed-in advisor's own students.
 */
class AdvisorController extends Controller
{
    /** Risk at or above this (share of similar students a semester+ behind) counts as high. */
    public const HIGH_RISK = 0.5;

    /** "Critical" = this share of an advisor's own students with the most pressing standing (relative, never saturates). */
    public const CRITICAL_SHARE = 0.10;

    /** @var array<int, float> per-request cache of each advisor's critical threshold */
    private array $thresholds = [];

    public function __construct(
        private readonly TwinService $twin,
        private readonly MessagingService $messages,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $advisor = $request->user()->load('academicPlan');
        $students = $this->myStudents($advisor);

        $counts = (clone $students)
            ->leftJoin('twin_student_status as s', 's.student_id', '=', 'u.id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN s.risk >= ? THEN 1 ELSE 0 END) as high_risk', [self::HIGH_RISK])
            ->selectRaw('SUM(CASE WHEN '.$this->needSql().' >= '.self::sqlNumber($this->criticalThreshold($advisor)).' THEN 1 ELSE 0 END) as critical')
            ->selectRaw("SUM(CASE WHEN s.lane IS NOT NULL AND s.lane <> 'on' THEN 1 ELSE 0 END) as behind")
            ->first();

        return response()->json([
            'data' => [
                'advisor' => [
                    'full_name' => $advisor->full_name,
                    'department' => $advisor->academicPlan?->major,
                    'college' => $advisor->academicPlan?->college,
                ],
                'counts' => [
                    'students' => (int) $counts->total,
                    'high_risk' => (int) $counts->high_risk,
                    'critical' => (int) $counts->critical,
                    'behind' => (int) $counts->behind,
                    'unseen_alerts' => DB::table('advisor_alerts')->where('advisor_id', $advisor->id)->whereNull('seen_at')->count(),
                ],
                'alerts' => $this->alerts($advisor->id)->limit(15)->get()->map($this->formatAlert(...))->values(),
                'priorities' => $this->priorities($advisor),
                'impact' => $this->twin->impact($advisor->id),
                'week' => $this->week($advisor),
                'unread_replies' => $this->messages->unreadRepliesForAdvisor($advisor),
                'high_risk_threshold' => self::HIGH_RISK,
            ],
        ]);
    }

    public function students(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'risk', 'alerts', 'behind'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $advisorId = $request->user()->id;
        $threshold = $this->criticalThreshold($request->user());

        $query = $this->myStudents($request->user())
            ->leftJoin('twin_student_status as s', 's.student_id', '=', 'u.id')
            ->select('u.id', 'u.full_name', 'u.email', 's.term_no', 's.gpa', 's.lane', 's.gap_hours', 's.failed_pending', 's.registered_hours', 's.risk')
            ->selectRaw($this->needSql().' as need')
            ->selectSub(fn (Builder $q) => $q->from('advisor_alerts as a')->selectRaw('COUNT(*)')
                ->whereColumn('a.student_id', 'u.id')->where('a.advisor_id', $advisorId)->whereNull('a.seen_at'), 'unseen_alerts')
            ->selectSub(fn (Builder $q) => $q->from('advisor_notes as n')->selectRaw('MAX(n.created_at)')
                ->whereColumn('n.student_id', 'u.id')->where('n.advisor_id', $advisorId)->where('n.contacted', true), 'last_contact_at');

        match ($data['filter'] ?? 'all') {
            'risk' => $query->whereRaw($this->needSql().' >= '.self::sqlNumber($threshold)),
            'behind' => $query->where('s.lane', '<>', 'on'),
            'alerts' => $query->whereExists(fn (Builder $q) => $q->from('advisor_alerts as a')
                ->whereColumn('a.student_id', 'u.id')->where('a.advisor_id', $advisorId)->whereNull('a.seen_at')),
            default => null,
        };

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $like = str_replace(['%', '_'], ['\%', '\_'], $term);
            $query->where(fn (Builder $q) => $q->where('u.full_name', 'like', "%{$like}%")->orWhere('u.email', 'like', "{$like}%"));
        }

        // Students with unseen alerts first, then highest risk, then furthest behind plan.
        $page = $query->orderByDesc('unseen_alerts')
            ->orderByRaw('CASE WHEN s.risk IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('s.risk')->orderByDesc('s.gap_hours')->orderBy('u.id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn ($row) => [
                'id' => $row->id,
                'student_id' => explode('@', $row->email)[0],
                'full_name' => $row->full_name,
                'term_no' => $row->term_no,
                'gpa' => $row->gpa !== null ? round((float) $row->gpa, 2) : null,
                'lane' => $row->lane,
                'gap_hours' => $row->gap_hours,
                'failed_pending' => $row->failed_pending,
                'registered_hours' => $row->registered_hours,
                'risk' => $row->risk !== null ? round((float) $row->risk, 3) : null,
                'tier' => $this->tier($row, $threshold),
                'unseen_alerts' => (int) $row->unseen_alerts,
                'last_contact_at' => $row->last_contact_at,
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function student(Request $request, int $student): JsonResponse
    {
        $user = $this->findMyStudent($request->user(), $student)->load('academicPlan');

        return response()->json([
            'data' => [
                'student' => [
                    'id' => $user->id,
                    'student_id' => $user->studentCode(),
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'study_year' => $user->studyYear(),
                    'major' => $user->academicPlan?->major,
                ],
                'twin' => $this->twin->isReady() ? $this->twin->status($user) : ['ready' => false],
                'alerts' => $this->alerts($request->user()->id)->where('al.student_id', $user->id)->limit(20)->get()->map($this->formatAlert(...))->values(),
                'messages' => $this->threadFor($request->user(), $user),
                'notes' => DB::table('advisor_notes')->where('advisor_id', $request->user()->id)->where('student_id', $user->id)
                    ->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get(['id', 'body', 'contacted', 'created_at']),
            ],
        ]);
    }

    public function storeNote(Request $request, int $student): JsonResponse
    {
        $user = $this->findMyStudent($request->user(), $student);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'contacted' => ['sometimes', 'boolean'],
        ]);
        $contacted = (bool) ($data['contacted'] ?? false);

        $id = DB::transaction(function () use ($request, $user, $data, $contacted) {
            $id = DB::table('advisor_notes')->insertGetId([
                'advisor_id' => $request->user()->id,
                'student_id' => $user->id,
                'body' => trim($data['body']),
                'contacted' => $contacted,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Reaching out to the student settles their open alerts.
            if ($contacted) {
                DB::table('advisor_alerts')->where('advisor_id', $request->user()->id)->where('student_id', $user->id)
                    ->whereNull('seen_at')->update(['seen_at' => now(), 'updated_at' => now()]);
            }

            return $id;
        });

        return response()->json(['message' => __('advising.note_saved'), 'data' => ['id' => $id]], 201);
    }

    public function sendMessage(Request $request, int $student): JsonResponse
    {
        $user = $this->findMyStudent($request->user(), $student);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'alert_id' => ['nullable', 'integer'],
        ]);

        $id = $this->messages->sendFromAdvisor($request->user(), $user, $data['body'], $data['alert_id'] ?? null);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    /** Opening the student's file reads their replies. */
    private function threadFor(User $advisor, User $student): array
    {
        $thread = $this->messages->thread($advisor->id, $student->id);
        $this->messages->markRead($advisor->id, $student->id, MessagingService::SENDER_ADVISOR);

        return $thread;
    }

    /** "Talk to them before the drop deadline": up to a day's worth of students, with the reason for each. */
    public function beforeDeadline(Request $request, DropDeadlineService $deadline): JsonResponse
    {
        return response()->json(['data' => $deadline->forAdvisor($request->user())]);
    }

    public function markAlertSeen(Request $request, int $alert): JsonResponse
    {
        $updated = DB::table('advisor_alerts')->where('id', $alert)->where('advisor_id', $request->user()->id)
            ->update(['seen_at' => now(), 'updated_at' => now()]);

        abort_if($updated === 0, 404);

        return response()->json(['data' => ['id' => $alert]]);
    }

    /**
     * How pressing a student's academic standing is (alerts aside): below-minimum load,
     * open failed courses, hours behind plan and the cohort risk. Same weights as the priority list.
     */
    private function needSql(): string
    {
        $min = (int) config('academic.registration.min_hours');

        return "(CASE WHEN s.registered_hours < {$min} THEN 30 ELSE 0 END + s.failed_pending * 5 + s.gap_hours + COALESCE(s.risk, 0) * 20)";
    }

    /** Score of the student at the top-10% boundary among this advisor's students. */
    private function criticalThreshold(User $advisor): float
    {
        if (isset($this->thresholds[$advisor->id])) {
            return $this->thresholds[$advisor->id];
        }
        $scores = $this->myStudents($advisor)->join('twin_student_status as s', 's.student_id', '=', 'u.id');
        $n = (clone $scores)->count();
        if ($n === 0) {
            return $this->thresholds[$advisor->id] = 1e9;
        }
        $k = max(1, (int) ceil($n * self::CRITICAL_SHARE));
        $value = (clone $scores)->selectRaw($this->needSql().' as need')->orderByDesc('need')->offset($k - 1)->limit(1)->value('need');

        return $this->thresholds[$advisor->id] = (float) $value;
    }

    /** The last 7 days in four numbers: new alerts, students contacted, drop prices seen, and not dropped after. */
    private function week(User $advisor): array
    {
        $since = now()->subDays(7);
        $views = DB::table('twin_decision_views as v')->join('users as u', 'u.id', '=', 'v.student_id')
            ->where('u.advisor_id', $advisor->id)->where('v.created_at', '>=', $since);

        return [
            'alerts' => DB::table('advisor_alerts')->where('advisor_id', $advisor->id)->where('created_at', '>=', $since)->count(),
            'contacted' => DB::table('advisor_notes')->where('advisor_id', $advisor->id)->where('contacted', true)
                ->where('created_at', '>=', $since)->distinct()->count('student_id'),
            'views' => (clone $views)->count(),
            'kept' => (clone $views)->where('v.dropped', false)->count(),
        ];
    }

    /** Inline numeric literal: SQLite would compare a bound float as text. */
    private static function sqlNumber(float $value): string
    {
        return sprintf('%.6F', $value);
    }

    private function tier(object $row, float $threshold): string
    {
        return match (true) {
            $row->need !== null && (float) $row->need >= $threshold => 'critical',
            $row->risk === null => 'none',
            $row->risk >= self::HIGH_RISK => 'high',
            $row->risk >= 0.25 => 'mid',
            default => 'low',
        };
    }

    /**
     * "Who to see this week": a short work list instead of hundreds of high-risk rows.
     * Unseen alerts first, then below-minimum load, open failed courses and distance from plan.
     * Students contacted in the last 7 days are left out.
     */
    private function priorities(User $advisor): array
    {
        $min = (int) config('academic.registration.min_hours');
        $since = now()->subDays(7);

        $rows = $this->myStudents($advisor)
            ->join('twin_student_status as s', 's.student_id', '=', 'u.id')
            ->select('u.id', 'u.full_name', 'u.email', 's.gap_hours', 's.failed_pending', 's.registered_hours', 's.gpa', 's.risk')
            ->selectSub(fn (Builder $q) => $q->from('advisor_alerts as a')->selectRaw('COUNT(*)')
                ->whereColumn('a.student_id', 'u.id')->where('a.advisor_id', $advisor->id)->whereNull('a.seen_at'), 'unseen_alerts')
            ->selectSub(fn (Builder $q) => $q->from('advisor_messages as m')->selectRaw('COUNT(*)')
                ->whereColumn('m.student_id', 'u.id')->where('m.advisor_id', $advisor->id)->where('m.sender', 'student')->whereNull('m.read_at'), 'unread_replies')
            // Recently contacted students drop off the list, unless they have since written back.
            ->where(fn (Builder $w) => $w
                ->whereNotExists(fn (Builder $q) => $q->from('advisor_notes as n')->whereColumn('n.student_id', 'u.id')
                    ->where('n.advisor_id', $advisor->id)->where('n.contacted', true)->where('n.created_at', '>=', $since))
                ->orWhereExists(fn (Builder $q) => $q->from('advisor_messages as m3')->whereColumn('m3.student_id', 'u.id')
                    ->where('m3.advisor_id', $advisor->id)->where('m3.sender', 'student')->whereNull('m3.read_at')))
            ->orderByRaw(
                '(SELECT COUNT(*) FROM advisor_messages m2 WHERE m2.student_id = u.id AND m2.advisor_id = ? AND m2.sender = \'student\' AND m2.read_at IS NULL) * 2000'
                .' + (SELECT COUNT(*) FROM advisor_alerts a2 WHERE a2.student_id = u.id AND a2.advisor_id = ? AND a2.seen_at IS NULL) * 1000'
                .' + CASE WHEN s.registered_hours < ? THEN 30 ELSE 0 END + s.failed_pending * 5 + s.gap_hours + COALESCE(s.risk, 0) * 20 DESC',
                [$advisor->id, $advisor->id, $min]
            )
            ->limit(5)
            ->get();

        return $rows->map(function ($r) use ($min) {
            $reasons = [];
            if ($r->unread_replies > 0) {
                $reasons[] = ['code' => 'reply', 'count' => (int) $r->unread_replies];
            }
            if ($r->unseen_alerts > 0) {
                $reasons[] = ['code' => 'alert', 'count' => (int) $r->unseen_alerts];
            }
            if ($r->registered_hours < $min) {
                $reasons[] = ['code' => 'below_min', 'hours' => (int) $r->registered_hours, 'min' => $min];
            }
            if ($r->failed_pending > 0) {
                $reasons[] = ['code' => 'failed', 'count' => (int) $r->failed_pending];
            }
            if ($r->gap_hours > 3) {
                $reasons[] = ['code' => 'behind', 'hours' => (int) $r->gap_hours];
            }

            return [
                'id' => $r->id,
                'student_id' => explode('@', $r->email)[0],
                'full_name' => $r->full_name,
                'gpa' => $r->gpa !== null ? round((float) $r->gpa, 2) : null,
                'risk' => $r->risk !== null ? round((float) $r->risk, 3) : null,
                'reasons' => array_slice($reasons, 0, 3),
            ];
        })->values()->all();
    }

    private function myStudents(User $advisor): Builder
    {
        return DB::table('users as u')->where('u.advisor_id', $advisor->id)->where('u.role', User::ROLE_STUDENT);
    }

    private function findMyStudent(User $advisor, int $id): User
    {
        $student = User::query()->whereKey($id)->where('advisor_id', $advisor->id)->where('role', User::ROLE_STUDENT)->first();

        // 404 (not 403) so advisors can't probe which ids exist.
        abort_unless($student, 404, __('advising.not_your_student'));

        return $student;
    }

    private function alerts(int $advisorId): Builder
    {
        return DB::table('advisor_alerts as al')
            ->join('users as st', 'st.id', '=', 'al.student_id')
            ->leftJoin('courses as c', 'c.id', '=', 'al.course_id')
            ->where('al.advisor_id', $advisorId)
            ->orderByRaw('CASE WHEN al.seen_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('al.created_at')->orderByDesc('al.id')
            ->select('al.id', 'al.type', 'al.details', 'al.seen_at', 'al.created_at', 'st.id as student_id', 'st.full_name', 'st.email', 'c.code', 'c.name as course_name');
    }

    private function formatAlert(object $row): array
    {
        return [
            'id' => $row->id,
            'type' => $row->type,
            'student' => ['id' => $row->student_id, 'full_name' => $row->full_name, 'student_id' => explode('@', $row->email)[0]],
            'course' => $row->code ? ['code' => $row->code, 'name' => $row->course_name] : null,
            'details' => $row->details ? json_decode($row->details, true) : null,
            'seen_at' => $row->seen_at,
            'created_at' => $row->created_at,
        ];
    }
}
