<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_crud_is_tenant_scoped(): void
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'A', 'slug' => 'a']);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);

        $product = $this->actingAs($user)->postJson('/api/v1/products', [
            'name' => 'Widget', 'sku' => 'W-1', 'unit_price' => 1250, 'tax_rate' => 7.5,
        ])->assertCreated()->json('data');

        $this->actingAs($user)->patchJson("/api/v1/products/{$product['id']}", ['unit_price' => 1500])
            ->assertOk()->assertJsonPath('data.unit_price', '1500.00');

        $this->assertDatabaseHas('products', ['id' => $product['id'], 'business_id' => $business->id]);
    }

    public function test_product_from_another_business_is_not_found(): void
    {
        $user = User::factory()->create();
        $businessA = Business::create(['name' => 'A', 'slug' => 'a']);
        $businessB = Business::create(['name' => 'B', 'slug' => 'b']);
        $user->businesses()->attach($businessA->id, ['role' => 'owner', 'status' => 'active']);

        app(\App\Tenancy\TenantContext::class)->set($businessB->id);
        $product = Product::create(['name' => 'Private', 'unit_price' => 1]);
        app(\App\Tenancy\TenantContext::class)->clear();

        $this->actingAs($user)->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    }
}
