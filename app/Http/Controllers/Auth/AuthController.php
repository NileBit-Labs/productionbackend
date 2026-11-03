<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        abort_unless(
            config('beta.public_registration'),
            403,
            'Public registration is currently closed. Please contact NileBit Labs for an invitation.',
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'organization_name' => ['required', 'string', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $organization = Organization::create([
                'name' => $data['organization_name'],
                'owner_user_id' => $user->id,
            ]);

            $user->update(['organization_id' => $organization->id]);

            return $user;
        });

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->fresh(['organization', 'shopRoles.shop']),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'client' => ['nullable', 'in:web,whatsapp'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Please ask the shop owner.'],
            ]);
        }

        $bot = ($data['client'] ?? 'web') === 'whatsapp';
        $token = $bot ? $user->createToken('nilebit-whatsapp', ['pos:bot'], now()->addDays(90))->plainTextToken : $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->fresh(['organization', 'shopRoles.shop']),
            'token' => $token,
            'token_scope' => $bot ? 'pos:bot' : '*',
            'can_create_business' => $user->organization()->where('owner_user_id', $user->id)->exists(),
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Do not reveal whether an address is registered, or whether mail delivery is temporarily
        // unavailable. The latter would otherwise become an account-enumeration side channel.
        try {
            Password::sendResetLink(['email' => $data['email']]);
        } catch (Throwable $e) {
            Log::warning('password_reset_delivery_failed', ['exception' => $e::class]);
        }

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'min:8', 'max:100'],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['The password reset link is invalid or has expired.'],
            ]);
        }

        return response()->json(['message' => 'Password reset. Please sign in with your new password.']);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'different:current_password'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['That is not your current password.']]);
        }

        $user->update(['password' => $data['password']]);

        // Every other signed-in device is signed out; this one stays.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();
        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token]);
    }

    public function connections(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);

        return response()->json($request->user()->tokens()->where('name', 'nilebit-whatsapp')
            ->orderByDesc('id')->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']));
    }

    public function revokeConnection(Request $request, int $connection)
    {
        abort_unless($request->user()->status === 'active', 403);
        $request->user()->tokens()->where('name', 'nilebit-whatsapp')->findOrFail($connection)->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return $request->user()->load('organization', 'shopRoles.shop');
    }
}
