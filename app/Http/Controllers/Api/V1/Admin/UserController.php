<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class UserController extends ApiController
{
    public function index(Request $request)
    {
        $query = User::query()->with(['businesses' => fn ($q) => $q->select('businesses.id', 'businesses.name', 'businesses.slug', 'businesses.status')]);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($request->boolean('super_admins')) {
            $query->where('is_super_admin', true);
        }

        $page = $query->latest()->paginate(min((int) $request->query('per_page', 20), 100));
        $page->getCollection()->transform(fn (User $user) => $this->present($user));

        return $this->successResponse('Users retrieved successfully.', $page);
    }

    public function show(int $user)
    {
        $record = User::query()->with('businesses')->findOrFail($user);

        return $this->successResponse('User retrieved successfully.', $this->present($record) + [
            'tokens_count' => $record->tokens()->count(),
        ]);
    }

    /**
     * Toggle platform admin access. An admin cannot remove their own access,
     * so there is always at least one left.
     */
    public function setSuperAdmin(Request $request, int $user)
    {
        $validated = $request->validate(['is_super_admin' => ['required', 'boolean']]);
        $record = User::query()->findOrFail($user);

        if ($record->id === $request->user()->id && !$validated['is_super_admin']) {
            return $this->errorResponse('You cannot remove your own super admin access.', 422);
        }

        $record->forceFill(['is_super_admin' => $validated['is_super_admin']])->save();

        return $this->successResponse($validated['is_super_admin'] ? 'Super admin access granted.' : 'Super admin access revoked.', $this->present($record->fresh('businesses')));
    }

    public function verifyEmail(int $user)
    {
        $record = User::query()->findOrFail($user);
        if (!$record->hasVerifiedEmail()) {
            $record->markEmailAsVerified();
        }

        return $this->successResponse('Email marked as verified.', $this->present($record->fresh('businesses')));
    }

    public function sendPasswordReset(int $user)
    {
        $record = User::query()->findOrFail($user);
        Password::sendResetLink(['email' => $record->email]);

        return $this->successResponse('Password reset link sent.');
    }

    /** Revoke every API token, forcing the user to log in again everywhere. */
    public function revokeTokens(int $user)
    {
        $record = User::query()->findOrFail($user);
        $record->tokens()->delete();

        return $this->successResponse('All sessions for this user were signed out.');
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'email_verified_at' => $user->email_verified_at,
            'is_super_admin' => $user->isSuperAdmin(),
            'created_at' => $user->created_at,
            'businesses' => $user->businesses->map(fn (Business $b) => [
                'id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'status' => $b->status,
                'role' => $b->pivot->role, 'membership_status' => $b->pivot->status,
            ])->values(),
        ];
    }
}
