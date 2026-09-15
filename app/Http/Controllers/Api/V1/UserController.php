<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\User\InviteUserRequest;
use App\Models\Business;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Team management for the current business. Memberships live in
 * business_users, so a user can be a member of several businesses.
 */
class UserController extends ApiController
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function index()
    {
        $members = $this->business()->users()
            ->orderByPivot('created_at')
            ->get()
            ->map(fn (User $user) => $this->present($user));

        return $this->successResponse('Team members retrieved successfully.', $members);
    }

    /**
     * Add a member. New accounts receive a password-setup email; existing
     * accounts are simply attached to the business.
     */
    public function invite(InviteUserRequest $request)
    {
        $business = $this->business();
        $data = $request->validated();

        if ($business->users()->where('email', $data['email'])->exists()) {
            return $this->errorResponse('That person is already a member of this business.', 422);
        }

        $user = DB::transaction(function () use ($business, $data) {
            $user = User::query()->where('email', $data['email'])->first();
            $isNew = !$user;

            if ($isNew) {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => Str::random(40),
                ]);
            }

            $business->users()->attach($user->id, ['role' => $data['role'], 'status' => 'active']);
            $this->audit->log('user.invited', $user, null, null, ['email' => $user->email, 'role' => $data['role']]);

            if ($isNew) {
                // The reset-link email doubles as the invitation.
                Password::sendResetLink(['email' => $user->email]);
            }

            return $user;
        });

        return $this->successResponse('Team member invited successfully.', $this->present($this->member($business, $user->id)), 201);
    }

    public function updateRole(Request $request, int $user)
    {
        $validated = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);
        $business = $this->business();
        $member = $this->member($business, $user);

        if ($member->id === $request->user()->id) {
            return $this->errorResponse('You cannot change your own role.', 422);
        }
        if ($member->pivot->role === 'owner' && $validated['role'] !== 'owner' && $this->ownerCount($business) <= 1) {
            return $this->errorResponse('A business must keep at least one owner.', 422);
        }

        $old = $member->pivot->role;
        $business->users()->updateExistingPivot($member->id, ['role' => $validated['role']]);
        $this->audit->log('user.role_changed', $member, null, ['role' => $old], ['role' => $validated['role']]);

        return $this->successResponse('Role updated successfully.', $this->present($this->member($business, $user)));
    }

    public function destroy(Request $request, int $user)
    {
        $business = $this->business();
        $member = $this->member($business, $user);

        if ($member->id === $request->user()->id) {
            return $this->errorResponse('You cannot remove yourself from the business.', 422);
        }
        if ($member->pivot->role === 'owner' && $this->ownerCount($business) <= 1) {
            return $this->errorResponse('A business must keep at least one owner.', 422);
        }

        $business->users()->detach($member->id);
        $member->tokens()->delete();
        $this->audit->log('user.removed', $member, null, ['email' => $member->email, 'role' => $member->pivot->role]);

        return $this->successResponse('Team member removed successfully.');
    }

    private function business(): Business
    {
        return Business::query()->findOrFail(TenantManager::businessId());
    }

    private function member(Business $business, int $userId): User
    {
        return $business->users()->where('users.id', $userId)->firstOrFail();
    }

    private function ownerCount(Business $business): int
    {
        return $business->users()->wherePivot('role', 'owner')->wherePivot('status', 'active')->count();
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->pivot->role,
            'status' => $user->pivot->status,
            'email_verified_at' => $user->email_verified_at,
            'joined_at' => $user->pivot->created_at,
        ];
    }
}
