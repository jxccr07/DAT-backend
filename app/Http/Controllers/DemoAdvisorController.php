<?php

namespace App\Http\Controllers;

use App\Support\DemoAccounts;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Demo-only: sign-in details of a seeded academic advisor. Prefers an advisor
 * with unseen decision alerts, so the visitor lands on a page with something to act on.
 * Disabled unless DEMO_MODE=true.
 */
class DemoAdvisorController extends Controller
{
    public function __invoke(): JsonResponse
    {
        abort_unless(config('academic.demo.enabled'), 404);

        // The pinned demo advisor (production) or the one with the most open alerts; else any advisor,
        // but never a random one once an advisor is pinned: every other account is locked then.
        $demoId = DemoAccounts::advisorId();

        $advisor = $demoId
            ? User::query()->find($demoId, ['email'])
            : (DemoAccounts::isPinned() ? null : $this->randomAdvisor());

        abort_unless($advisor, 404);

        return response()->json([
            'data' => [
                'email' => $advisor->email,
                'password' => (string) config('academic.demo.password'),
            ],
        ]);
    }

    private function randomAdvisor(): ?User
    {
        $ids = User::query()->where('role', User::ROLE_ADVISOR)->selectRaw('MIN(id) as lo, MAX(id) as hi')->first();
        if (! $ids?->lo) {
            return null;
        }

        return User::query()->where('role', User::ROLE_ADVISOR)->where('id', '>=', random_int((int) $ids->lo, (int) $ids->hi))
            ->orderBy('id')->first(['email']);
    }
}
