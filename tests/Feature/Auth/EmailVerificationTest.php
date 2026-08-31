<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_verify_their_signed_email_link(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('success', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_user_cannot_verify_another_users_email_link(): void
    {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $otherUser->id,
            'hash' => sha1($otherUser->getEmailForVerification()),
        ]);

        $this->actingAs($user)->getJson($url)->assertForbidden();
        $this->assertNull($otherUser->fresh()->email_verified_at);
    }
}
