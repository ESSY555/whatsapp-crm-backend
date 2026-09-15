<?php

namespace Tests\Concerns;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\InvoiceService;
use App\Tenancy\TenantManager;
use Closure;

/**
 * Fixture helpers shared by feature tests. Records are created inside an
 * explicit tenant context, exactly as the application does.
 */
trait CreatesTenants
{
    /** @return array{0: User, 1: Business} */
    protected function membership(string $name = 'Acme', ?string $slug = null, string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => $name, 'slug' => $slug ?? strtolower($name) . '-' . uniqid(), 'currency' => 'USD', 'status' => 'active']);
        $user->businesses()->attach($business->id, ['role' => $role, 'status' => 'active']);

        return [$user, $business];
    }

    protected function asTenant(int $businessId, Closure $callback): mixed
    {
        return TenantManager::runAs($businessId, $callback);
    }

    protected function connectionFor(Business $business, string $phoneId = 'phone-1'): WhatsAppConnection
    {
        return $this->asTenant($business->id, fn () => WhatsAppConnection::create([
            'phone_number_id' => $phoneId,
            'business_account_id' => 'waba-' . $phoneId,
            'access_token_encrypted' => 'secret-token',
            'status' => 'active',
            'connected_at' => now(),
        ]));
    }

    protected function customerFor(Business $business, array $attributes = []): Customer
    {
        return $this->asTenant($business->id, fn () => Customer::create(array_merge([
            'name' => 'Buyer ' . uniqid(),
            'phone' => '+2348030000001',
            'email' => uniqid() . '@example.com',
        ], $attributes)));
    }

    protected function invoiceFor(Business $business, Customer $customer, User $user, float $amount = 100, array $attributes = []): Invoice
    {
        return $this->asTenant($business->id, fn () => app(InvoiceService::class)->create(array_merge([
            'customer_id' => $customer->id,
            'status' => 'sent',
            'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount]],
        ], $attributes), $user->id));
    }

    protected function templateFor(Business $business, array $attributes = []): MessageTemplate
    {
        return $this->asTenant($business->id, fn () => MessageTemplate::create(array_merge([
            'name' => 'Template ' . uniqid(),
            'content' => 'Hello {{customer_name}}, invoice {{invoice_number}} balance {{balance_due}}.',
            'status' => 'approved',
            'language' => 'en',
        ], $attributes)));
    }
}
