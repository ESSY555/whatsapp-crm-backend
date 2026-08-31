<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_and_full_payments_update_invoice_balance_and_status(): void
    {
        [$user, $business, $invoice] = $this->invoiceFixture(100);

        $this->actingAs($user)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id, 'amount' => 40,
            'payment_method' => 'cash', 'reference' => 'PAY-1',
        ])->assertCreated();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'amount_paid' => 40, 'balance_due' => 60, 'status' => 'partially_paid']);

        $this->actingAs($user)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id, 'amount' => 60,
            'payment_method' => 'bank_transfer', 'reference' => 'PAY-2',
        ])->assertCreated();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'amount_paid' => 100, 'balance_due' => 0, 'status' => 'paid']);
    }

    public function test_payment_cannot_exceed_balance_or_reuse_reference(): void
    {
        [$user, , $invoice] = $this->invoiceFixture(50);
        $payload = ['invoice_id' => $invoice->id, 'amount' => 50, 'payment_method' => 'cash', 'reference' => 'DUP-1'];
        $this->actingAs($user)->postJson('/api/v1/payments', $payload)->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/payments', $payload)->assertUnprocessable()->assertJsonValidationErrors('reference');
    }

    public function test_payment_from_another_business_is_not_accessible(): void
    {
        [$user, $business] = $this->membership('A', 'a');
        [$otherUser, $otherBusiness, $invoice] = $this->invoiceFixture(20, 'B', 'b');
        $this->actingAs($otherUser)->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 20, 'payment_method' => 'cash'])->assertCreated();

        $this->actingAs($user)->getJson('/api/v1/payments/' . $invoice->payments()->first()->id)->assertNotFound();
    }

    private function invoiceFixture(float $total, string $name = 'A', string $slug = 'a'): array
    {
        [$user, $business] = $this->membership($name, $slug);
        app(\App\Tenancy\TenantContext::class)->set($business->id);
        $customer = Customer::create(['name' => 'Buyer']);
        $invoice = app(InvoiceService::class)->create([
            'customer_id' => $customer->id,
            'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $total]],
        ], $user->id);
        app(\App\Tenancy\TenantContext::class)->clear();
        return [$user, $business, $invoice];
    }

    private function membership(string $name, string $slug): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => $name, 'slug' => $slug]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);
        return [$user, $business];
    }
}
