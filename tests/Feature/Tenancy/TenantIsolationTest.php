<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Business;
use App\Models\Customer;
use Illuminate\Support\Facades\Route;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->group(function () {
            Route::middleware(\App\Tenancy\Middleware\ResolveTenant::class)->group(function () {
                Route::get('/api/v1/test/customers/{id}', function ($id) {
                    return Customer::findOrFail($id);
                });
                Route::post('/api/v1/test/customers', function (\Illuminate\Http\Request $request) {
                    return Customer::create($request->all());
                });
            });
        });
    }

    public function test_business_a_cannot_access_business_b_data()
    {
        $userA = User::factory()->create();
        $businessA = Business::create(['name' => 'Business A', 'slug' => 'business-a']);
        $businessA->users()->attach($userA, ['role' => 'owner']);

        $userB = User::factory()->create();
        $businessB = Business::create(['name' => 'Business B', 'slug' => 'business-b']);
        $businessB->users()->attach($userB, ['role' => 'owner']);

        // Create Customer for Business B
        \App\Tenancy\TenantManager::set($businessB->id);
        $customerB = Customer::create(['name' => 'Customer B']);
        \App\Tenancy\TenantManager::clear();

        // Attempt access as User A with Business A context
        $response = $this->actingAs($userA)
            ->withHeader('X-Business-ID', (string) $businessA->id)
            ->getJson("/api/v1/test/customers/{$customerB->id}");

        $response->assertStatus(404);
    }

    public function test_malicious_business_id_is_ignored_on_creation()
    {
        $userA = User::factory()->create();
        $businessA = Business::create(['name' => 'Business A', 'slug' => 'business-a']);
        $businessA->users()->attach($userA, ['role' => 'owner']);

        $businessB = Business::create(['name' => 'Business B', 'slug' => 'business-b']);

        // Attempt to create a customer belonging to Business B while logged in as Business A
        $response = $this->actingAs($userA)
            ->withHeader('X-Business-ID', (string) $businessA->id)
            ->postJson('/api/v1/test/customers', [
                'name' => 'Hacked Customer',
                'business_id' => $businessB->id // Malicious payload
            ]);

        $response->assertStatus(201);
        
        $customer = Customer::first();
        // Ensure the business_id was overridden to the authenticated tenant context
        $this->assertEquals($businessA->id, $customer->business_id);
        $this->assertNotEquals($businessB->id, $customer->business_id);
    }
}
