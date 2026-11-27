<?php

namespace App\Console\Commands;

use App\Models\AcademicPlan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Creates academic advisors per study plan (department) and spreads that plan's
 * students across them, about `--caseload` students each (national average ≈ 300).
 * Safe to re-run: existing advisors are reused, only missing ones are created.
 */
class AssignAdvisorsCommand extends Command
{
    protected $signature = 'advising:assign {--caseload=300 : Students per advisor}';

    protected $description = 'Create academic advisors per plan and assign every student to one';

    private const FIRST = ['عبدالله', 'محمد', 'سارة', 'نورة', 'خالد', 'ريم', 'فهد', 'هند', 'سلطان', 'منيرة', 'ماجد', 'لمى', 'تركي', 'العنود', 'ناصر', 'غادة'];

    private const LAST = ['القحطاني', 'الشهري', 'العتيبي', 'الدوسري', 'الغامدي', 'الزهراني', 'الحربي', 'المطيري', 'السبيعي', 'العمري', 'الشمري', 'الأحمدي'];

    public function handle(): int
    {
        $caseload = max(1, (int) $this->option('caseload'));
        $password = Hash::make((string) config('academic.demo.password', 'password'));

        $counts = DB::table('users')->where('role', User::ROLE_STUDENT)->whereNotNull('academic_plan_id')
            ->groupBy('academic_plan_id')->pluck(DB::raw('COUNT(*)'), 'academic_plan_id');

        Schema::dropIfExists('advising_w_slots');
        Schema::create('advising_w_slots', function ($table) {
            $table->unsignedBigInteger('plan_id');
            $table->unsignedInteger('slot');
            $table->unsignedInteger('k');
            $table->unsignedBigInteger('advisor_id');
            $table->primary(['plan_id', 'slot']);
        });

        try {
            foreach ($counts as $planId => $students) {
                $k = (int) ceil($students / $caseload);
                $advisorIds = $this->ensureAdvisors((int) $planId, $k, $password);
                $rows = array_map(fn ($id, $slot) => ['plan_id' => $planId, 'slot' => $slot, 'k' => $k, 'advisor_id' => $id], $advisorIds, array_keys($advisorIds));
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('advising_w_slots')->insert($chunk);
                }
                $this->components->twoColumnDetail(AcademicPlan::query()->whereKey($planId)->value('name') ?? "#{$planId}", "{$students} students → {$k} advisors");
            }

            // Spread each plan's students evenly: slot = id mod (advisors in that plan).
            $this->components->task('Assigning students', fn () => DB::statement(<<<'SQL'
                UPDATE users SET advisor_id = (
                    SELECT s.advisor_id FROM advising_w_slots s
                    WHERE s.plan_id = users.academic_plan_id
                      AND s.slot = users.id % (SELECT MAX(k) FROM advising_w_slots k WHERE k.plan_id = users.academic_plan_id)
                )
                WHERE role = 'student' AND academic_plan_id IS NOT NULL
            SQL));
        } finally {
            Schema::dropIfExists('advising_w_slots');
        }

        return self::SUCCESS;
    }

    /** @return array<int, int> slot => advisor id */
    private function ensureAdvisors(int $planId, int $k, string $password): array
    {
        $existing = DB::table('users')->where('role', User::ROLE_ADVISOR)->where('academic_plan_id', $planId)
            ->orderBy('id')->pluck('id')->all();

        for ($i = count($existing); $i < $k; $i++) {
            $n = $planId * 10000 + $i + 1;
            $existing[] = DB::table('users')->insertGetId([
                'full_name' => 'د. '.self::FIRST[$n % count(self::FIRST)].' '.self::LAST[intdiv($n, 7) % count(self::LAST)],
                'email' => sprintf('advisor%d.%d@example.com', $planId, $i + 1),
                'phone_number' => sprintf('07%08d', $n),
                'password' => $password,
                'role' => User::ROLE_ADVISOR,
                'academic_plan_id' => $planId,
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return array_slice($existing, 0, $k);
    }
}
