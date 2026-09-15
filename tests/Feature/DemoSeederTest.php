<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_a_consistent_workspace(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class); // idempotent

        $owner = User::where('email', 'demo@flowcrm.test')->firstOrFail();
        $this->assertSame(1, \App\Models\Business::count());

        $dashboard = $this->actingAs($owner)->getJson('/api/v1/dashboard')->assertOk()->json('data.metrics');
        $this->assertSame(6, $dashboard['total_customers']);
        $this->assertGreaterThan(0, $dashboard['overdue_amount']);
        $this->assertGreaterThan(0, $dashboard['total_collections']);

        $statuses = Invoice::withoutGlobalScopes()->pluck('status')->unique()->sort()->values()->all();
        $this->assertSame(['draft', 'overdue', 'paid', 'partially_paid', 'sent'], $statuses);

        $this->actingAs($owner)->postJson('/api/v1/auth/login', ['email' => 'demo@flowcrm.test', 'password' => 'password'])->assertOk();
    }
}
