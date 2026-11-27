<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Which accounts the public demo may hand out.
 *
 * In production one advisor is pinned (DEMO_ADVISOR_EMAIL); only that advisor and
 * their students keep a usable password (see demo:secure-accounts), so the demo
 * buttons must never point anywhere else. Without a pinned advisor (development),
 * fall back to the advisor with the most open alerts.
 */
class DemoAccounts
{
    public static function advisorId(): ?int
    {
        $email = config('academic.demo.advisor_email');
        if ($email) {
            $id = User::query()->where('email', $email)->where('role', User::ROLE_ADVISOR)->value('id');

            return $id ? (int) $id : null;
        }

        $id = DB::table('advisor_alerts')->whereNull('seen_at')
            ->groupBy('advisor_id')->orderByRaw('COUNT(*) DESC')->value('advisor_id');

        return $id ? (int) $id : null;
    }

    public static function isPinned(): bool
    {
        return (bool) config('academic.demo.advisor_email');
    }

    private const BASELINE_FILE = 'demo-baseline';

    /**
     * The moment the demo was prepared. Anything the demo pool created after it came from a
     * visitor (and is tidied away); anything before it is the prepared scenario (kept).
     * Kept in a file, not the cache, so clearing the cache can't turn visitor content into scenario.
     */
    public static function baseline(): Carbon
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::BASELINE_FILE)) {
            self::markBaseline();
        }

        return Carbon::parse(trim((string) $disk->get(self::BASELINE_FILE)));
    }

    public static function markBaseline(): void
    {
        Storage::disk('local')->put(self::BASELINE_FILE, now()->toDateTimeString());
    }
}
