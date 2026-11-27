<?php

namespace App\Console\Commands;

use App\Support\DemoAccounts;
use App\Support\DemoRegistrations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the public demo clean for the next visitor. Runs every minute: anything a visitor
 * wrote in the demo pool (messages, notes, new alerts and meeting requests, price views) lives
 * at most --minutes, so an abusive message disappears quickly while a visitor's own walkthrough
 * is never cut short. The prepared scenario's alerts are kept and put back to unread, and the
 * pool's current-term registrations are put back to the prepared ones (see DemoRegistrations).
 */
class TidyDemoCommand extends Command
{
    protected $signature = 'demo:tidy {--minutes=10 : How long visitor content stays visible}';

    protected $description = 'Remove visitor-made content from the demo accounts after a few minutes';

    public function handle(): int
    {
        if (! config('academic.demo.enabled') || ! DemoAccounts::isPinned()) {
            return self::SUCCESS; // only the public demo is tidied
        }
        $advisorId = DemoAccounts::advisorId();
        if (! $advisorId) {
            return self::SUCCESS;
        }

        $since = DemoAccounts::baseline();
        $cutoff = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $visitor = fn (string $table) => DB::table($table)->where('advisor_id', $advisorId)
            ->where('created_at', '>', $since)->where('created_at', '<', $cutoff);

        $removed = DB::transaction(function () use ($visitor, $advisorId, $since, $cutoff) {
            $counts = [
                'messages' => $visitor('advisor_messages')->delete(),
                'notes' => $visitor('advisor_notes')->delete(),
                'alerts' => $visitor('advisor_alerts')->delete(),
                'views' => DB::table('twin_decision_views')
                    ->whereIn('student_id', DB::table('users')->where('advisor_id', $advisorId)->select('id'))
                    ->where('created_at', '>', $since)->where('created_at', '<', $cutoff)->delete(),
            ];
            // The prepared scenario stays, unread again for the next visitor.
            $counts['reopened'] = DB::table('advisor_alerts')->where('advisor_id', $advisorId)
                ->where('created_at', '<=', $since)->where('seen_at', '<', $cutoff)->update(['seen_at' => null]);

            return $counts;
        });

        // Registrations visitors added or dropped go back to the prepared ones once the student has been quiet.
        $removed['registrations'] = DemoRegistrations::restore($advisorId, $cutoff);

        if (array_sum($removed) > 0) {
            $this->line(collect($removed)->map(fn ($n, $k) => "{$k}: {$n}")->join(', '));
        }

        return self::SUCCESS;
    }
}
