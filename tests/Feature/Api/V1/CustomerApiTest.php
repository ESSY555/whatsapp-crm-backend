<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private function userWithBusiness(string $suffix): array
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => "Business {$suffix}",
            'slug' => "business-{$suffix}",
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);

        return [$user, $business];
    }

    public function test_customer_is_created_for_authenticated_business_and_payload_tenant_is_ignored(): void
    {
        [$user, $business] = $this->userWithBusiness('a');
        [, $otherBusiness] = $this->userWithBusiness('b');

        $response = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Ada Lovelace',
            'phone' => '+2348000000000',
            'business_id' => $otherBusiness->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.business_id', $business->id);
        $this->assertDatabaseHas('customers', ['name' => 'Ada Lovelace', 'business_id' => $business->id]);
    }

    public function test_business_cannot_view_another_business_customer(): void
    {
        [$userA, $businessA] = $this->userWithBusiness('a');
        [, $businessB] = $this->userWithBusiness('b');

        app(\App\Tenancy\TenantContext::class)->set($businessB->id);
        $customer = Customer::create(['name' => 'Private customer']);
        app(\App\Tenancy\TenantContext::class)->clear();

        $this->actingAs($userA)
            ->withHeader('X-Business-ID', (string) $businessA->id)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_customer_groups_are_tenant_scoped_and_can_be_assigned(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $group = $this->actingAs($user)
            ->postJson('/api/v1/customer-groups', ['name' => 'Retailers'])
            ->assertCreated()
            ->json('data');

        $this->actingAs($user)
            ->postJson('/api/v1/customers', [
                'name' => 'Customer One',
                'group_ids' => [$group['id']],
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.groups');

        $this->assertDatabaseHas('customer_group_members', [
            'business_id' => $business->id,
            'customer_group_id' => $group['id'],
        ]);
    }
}
