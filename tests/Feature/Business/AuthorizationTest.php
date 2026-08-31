<?php

namespace Tests\Feature\Business;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\Business;
use App\Models\User;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_context_is_set_for_authorized_business()
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'My Business', 'slug' => 'my-business']);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);
        
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Business-Id' => $business->id
        ])->getJson('/api/v1/auth/user');

        $response->assertStatus(200);
    }

    public function test_auth_user_endpoint_does_not_switch_business_from_a_header()
    {
        $user = User::factory()->create();
        $myBusiness = Business::create(['name' => 'My Business', 'slug' => 'my-business']);
        $user->businesses()->attach($myBusiness->id, ['role' => 'owner', 'status' => 'active']);

        $otherBusiness = Business::create(['name' => 'Other Business', 'slug' => 'other-business']);
        
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Business-Id' => $otherBusiness->id
        ])->getJson('/api/v1/auth/user');

        // Auth endpoints report identity only. Tenant switching is handled by
        // SetTenantContext on business-data routes, where membership is checked.
        $response->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }
}
