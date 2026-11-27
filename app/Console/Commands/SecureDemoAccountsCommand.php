<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DemoAccounts;
use App\Support\DemoRegistrations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Before the site goes public: every generated account shares one known password, so
 * lock them all with a random secret nobody keeps, then give only the demo pool (the
 * pinned demo advisor and their students) the DEMO_PASSWORD the demo buttons hand out.
 * Also signs everyone out. Safe to run again (e.g. after changing DEMO_PASSWORD).
 */
class SecureDemoAccountsCommand extends Command
{
    protected $signature = 'demo:secure-accounts {--force : Skip the confirmation prompt}';

    protected $description = 'Lock every account except the demo advisor and their students';

    public function handle(): int
    {
        $password = (string) config('academic.demo.password');
        if (strlen($password) < 16 || strtolower($password) === 'password') {
            $this->error('Set DEMO_PASSWORD in .env to a long random value (16+ characters) first.');

            return self::FAILURE;
        }
        if (! DemoAccounts::isPinned()) {
            $this->error('Set DEMO_ADVISOR_EMAIL in .env to the demo advisor first.');

            return self::FAILURE;
        }
        $advisorId = DemoAccounts::advisorId();
        if (! $advisorId) {
            $this->error('DEMO_ADVISOR_EMAIL does not match any advisor.');

            return self::FAILURE;
        }

        $pool = User::query()->whereKey($advisorId)->orWhere('advisor_id', $advisorId);
        $poolCount = (clone $pool)->count();
        $total = User::query()->count();

        if (! $this->option('force') && ! $this->confirm("Lock {$total} accounts and keep {$poolCount} demo accounts open?")) {
            return self::FAILURE;
        }

        // One hash of a secret that is never stored or shown: no one can sign in to these accounts.
        $locked = Hash::make(Str::random(64));
        $open = Hash::make($password);

        DB::transaction(function () use ($locked, $open, $advisorId) {
            DB::table('users')->update(['password' => $locked, 'remember_token' => null]);
            DB::table('users')->where('id', $advisorId)->orWhere('advisor_id', $advisorId)->update(['password' => $open]);
            DB::table('personal_access_tokens')->delete();
        });

        // From now on, whatever the demo pool creates is visitor content (see demo:tidy).
        DemoAccounts::markBaseline();
        // ...and the pool's current registrations are the ones demo:tidy puts back.
        DemoRegistrations::snapshot($advisorId);

        $this->info("Locked {$total} accounts; {$poolCount} demo accounts use DEMO_PASSWORD. Everyone was signed out.");

        return self::SUCCESS;
    }
}
