<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\Request;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $business = Business::create([
                'name' => $validated['business_name'],
                'slug' => Str::slug($validated['business_name']) . '-' . Str::random(6),
                'status' => 'trial',
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $user->businesses()->attach($business->id, [
                'role' => 'owner',
                'status' => 'active',
            ]);

            DB::table('business_settings')->insert([
                'business_id' => $business->id,
                'key' => 'setup_completed',
                'value' => 'true',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $user;
        });

        event(new Registered($user));
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse('Successfully registered.', [
            'user' => $user->load('businesses'),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $deviceName = $validated['device_name'] ?? 'default_device';
        $token = $user->createToken($deviceName)->plainTextToken;

        return $this->successResponse('Successfully logged in.', [
            'user' => $user->load('businesses'),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse('Successfully logged out.');
    }

    public function user(Request $request)
    {
        $user = $request->user()->load('businesses');
        return $this->successResponse('Authenticated user retrieved successfully.', $user);
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        Password::sendResetLink($request->only('email'));

        // Do not disclose whether the email is registered.
        return $this->successResponse('If the email address exists, a password reset link has been sent.');
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $status = Password::reset($request->validated(), function (User $user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->successResponse('Password reset successfully. Please log in again.');
    }

    public function verifyEmail(Request $request, int $id, string $hash)
    {
        $user = $request->user();

        if (!$user || $user->id !== $id || !hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->errorResponse('You do not have permission to perform this action.', 403);
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $this->successResponse('Email verified successfully.');
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse('Email is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return $this->successResponse('Verification email sent.');
    }
}
