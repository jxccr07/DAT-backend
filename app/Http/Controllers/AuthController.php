<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Bcrypt (cost 12, the production BCRYPT_ROUNDS) of a random secret nobody kept. Checked when
     * the email is unknown, so that path costs about as much as a wrong password for a real account.
     */
    private const DUMMY_HASH = '$2y$12$tpullk1nSWR9bwdFz5oohOubjL2aGmnsqj7CRgma.6469EqxZaBbO';

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        // Same response, and about the same time, for unknown email and wrong password (no user enumeration).
        $valid = Hash::check($credentials['password'], $user?->password ?? self::DUMMY_HASH);
        if (! $user || ! $valid) {
            return response()->json([
                'message' => __('registration.invalid_credentials'),
                'code' => 'invalid_credentials',
            ], 422);
        }

        // Right credentials, wrong door: say which portal to use (only after the password checked out).
        if (isset($credentials['portal']) && $credentials['portal'] !== $user->role) {
            return response()->json([
                'message' => __('registration.wrong_portal_'.$user->role),
                'code' => 'wrong_portal_'.$user->role,
            ], 422);
        }

        $expiration = config('sanctum.expiration');
        $expiresAt = $expiration ? now()->addMinutes((int) $expiration) : null;

        $token = $user->createToken('user-api-token', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'message' => __('registration.logged_in'),
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt?->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => __('registration.logged_out')]);
    }
}
