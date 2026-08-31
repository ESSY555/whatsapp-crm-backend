<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function membership(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'Original', 'slug' => 'original']);
        $user->businesses()->attach($business->id, ['role' => $role, 'status' => 'active']);

        return [$user, $business];
    }

    public function test_owner_can_update_business_profile_but_not_status_or_slug(): void
    {
        [$user, $business] = $this->membership();

        $this->actingAs($user)->putJson('/api/v1/business', [
            'name' => 'Updated Name',
            'slug' => 'attacker-slug',
            'status' => 'cancelled',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('businesses', [
            'id' => $business->id,
            'name' => 'Updated Name',
            'slug' => 'original',
            'status' => 'trial',
        ]);
    }

    public function test_manager_can_update_settings_and_settings_are_tenant_scoped(): void
    {
        [$user, $business] = $this->membership('manager');

        $this->actingAs($user)->putJson('/api/v1/business/settings', [
            'settings' => ['invoice_prefix' => 'INV', 'reminders_enabled' => '1'],
        ])->assertOk()->assertJsonPath('data.invoice_prefix', 'INV');

        $this->assertDatabaseHas('business_settings', [
            'business_id' => $business->id,
            'key' => 'invoice_prefix',
            'value' => 'INV',
        ]);
    }

    public function test_inactive_membership_cannot_access_business_data(): void
    {
        [$user, $business] = $this->membership();
        $user->businesses()->updateExistingPivot($business->id, ['status' => 'suspended']);

        $this->actingAs($user)->getJson('/api/v1/business')->assertForbidden();
    }
}
