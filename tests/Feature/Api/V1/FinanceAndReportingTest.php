<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendWhatsAppMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class FinanceAndReportingTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    public function test_payment_reversal_restores_balance_and_is_audited(): void
    {
        [$user, $business] = $this->membership();
        $customer = $this->customerFor($business);
        $invoice = $this->invoiceFor($business, $customer, $user, 100);

        $payment = $this->actingAs($user)->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 100, 'payment_method' => 'cash', 'reference' => 'R1'])
            ->assertCreated()->json('data');
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid', 'balance_due' => 0]);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'payment_received']);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'payment.recorded', 'user_id' => $user->id]);

        $reversal = $this->actingAs($user)->postJson("/api/v1/payments/{$payment['id']}/reverse", ['reason' => 'Bounced cheque'])->assertCreated()->json('data');
        $this->assertSame('-100.00', $reversal['amount']);
        $this->assertSame('reversal', $reversal['status']);
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'status' => 'reversed']);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sent', 'amount_paid' => 0, 'balance_due' => 100]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.reversed', 'entity_id' => $payment['id']]);

        $this->actingAs($user)->postJson("/api/v1/payments/{$payment['id']}/reverse")->assertUnprocessable();
        $this->actingAs($user)->getJson("/api/v1/payments?invoice_id={$invoice->id}")->assertOk()->assertJsonCount(2, 'data.data');
    }

    public function test_idempotency_key_prevents_duplicate_payments(): void
    {
        [$user, $business] = $this->membership();
        $customer = $this->customerFor($business);
        $invoice = $this->invoiceFor($business, $customer, $user, 100);
        $payload = ['invoice_id' => $invoice->id, 'amount' => 40, 'payment_method' => 'cash'];

        $first = $this->actingAs($user)->withHeader('Idempotency-Key', 'abc-123')->postJson('/api/v1/payments', $payload)->assertCreated()->json('data.id');
        $second = $this->actingAs($user)->withHeader('Idempotency-Key', 'abc-123')->postJson('/api/v1/payments', $payload)->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'amount_paid' => 40, 'balance_due' => 60, 'status' => 'partially_paid']);
    }

    public function test_manager_cannot_reverse_payments_but_owner_can_manage_team(): void
    {
        [$owner, $business] = $this->membership();
        $customer = $this->customerFor($business);
        $invoice = $this->invoiceFor($business, $customer, $owner, 10);
        $payment = $this->actingAs($owner)->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 10, 'payment_method' => 'cash'])->json('data');

        NotificationFacade::fake();
        $member = $this->actingAs($owner)->postJson('/api/v1/users/invite', ['name' => 'Mani', 'email' => 'mani@example.com', 'role' => 'manager'])
            ->assertCreated()->assertJsonPath('data.role', 'manager')->json('data');
        NotificationFacade::assertSentTo(User::find($member['id']), \Illuminate\Auth\Notifications\ResetPassword::class);
        $this->actingAs($owner)->postJson('/api/v1/users/invite', ['name' => 'Mani', 'email' => 'mani@example.com', 'role' => 'manager'])->assertUnprocessable();

        $manager = User::find($member['id']);
        $this->actingAs($manager)->getJson('/api/v1/users')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($manager)->postJson("/api/v1/payments/{$payment['id']}/reverse")->assertForbidden();
        $this->actingAs($manager)->postJson('/api/v1/users/invite', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'manager'])->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/audit-logs')->assertOk();

        $this->actingAs($owner)->putJson("/api/v1/users/{$manager->id}/role", ['role' => 'owner'])->assertOk()->assertJsonPath('data.role', 'owner');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_changed', 'entity_id' => $manager->id]);
        $this->actingAs($owner)->putJson("/api/v1/users/{$owner->id}/role", ['role' => 'manager'])->assertUnprocessable();
        $this->actingAs($owner)->deleteJson("/api/v1/users/{$manager->id}")->assertOk();
        $this->assertDatabaseMissing('business_users', ['user_id' => $manager->id, 'business_id' => $business->id]);
        $this->actingAs($owner)->deleteJson("/api/v1/users/{$owner->id}")->assertUnprocessable();
    }

    public function test_invoice_send_remind_and_cancel_flow(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business, ['name' => 'Ada']);
        $invoice = $this->invoiceFor($business, $customer, $user, 250, ['status' => 'draft', 'due_date' => today()->addWeek()->toDateString()]);

        $result = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/send", ['via_whatsapp' => true])->assertOk()->json('data');
        $this->assertSame('sent', $result['invoice']['status']);
        $this->assertStringContainsString($invoice->invoice_number, $result['message']['body']);
        $this->assertStringContainsString('USD 250.00', $result['message']['body']);
        Queue::assertPushed(SendWhatsAppMessage::class, 1);

        $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/remind", ['message' => 'Please pay {{invoice_number}}'])->assertCreated()
            ->assertJsonPath('data.body', 'Please pay {{invoice_number}}');

        $this->actingAs($user)->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 50, 'payment_method' => 'cash'])->assertCreated();
        $this->actingAs($user)->deleteJson("/api/v1/invoices/{$invoice->id}")->assertUnprocessable();

        $draft = $this->invoiceFor($business, $customer, $user, 10, ['status' => 'draft']);
        $this->actingAs($user)->deleteJson("/api/v1/invoices/{$draft->id}")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($user)->postJson('/api/v1/payments', ['invoice_id' => $draft->id, 'amount' => 10, 'payment_method' => 'cash'])->assertUnprocessable();
        $this->actingAs($user)->getJson("/api/v1/invoices?search={$invoice->invoice_number}")->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($user)->getJson('/api/v1/invoices?open=1')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_customer_list_includes_balances_and_deletion_is_guarded(): void
    {
        [$user, $business] = $this->membership();
        $customer = $this->customerFor($business, ['name' => 'Debtor']);
        $this->invoiceFor($business, $customer, $user, 300);

        $row = $this->actingAs($user)->getJson('/api/v1/customers?with_balance=1')->assertOk()->assertJsonCount(1, 'data.data')->json('data.data.0');
        $this->assertEquals(300, $row['outstanding_balance']);
        $this->assertSame(1, $row['invoices_count']);

        $show = $this->actingAs($user)->getJson("/api/v1/customers/{$customer->id}")->assertOk()->json('data');
        $this->assertEquals(300, $show['stats']['outstanding_balance']);
        $this->assertCount(1, $show['invoices']);

        $this->actingAs($user)->deleteJson("/api/v1/customers/{$customer->id}")->assertUnprocessable();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);

        $free = $this->customerFor($business, ['name' => 'Free']);
        $this->actingAs($user)->deleteJson("/api/v1/customers/{$free->id}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $free->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.deleted', 'entity_id' => $free->id]);
    }

    public function test_dashboard_and_reports_are_accurate_and_tenant_scoped(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        [$otherUser, $otherBusiness] = $this->membership('Other');
        $this->connectionFor($business);
        $customer = $this->customerFor($business, ['name' => 'Top']);
        $second = $this->customerFor($business, ['name' => 'Second']);
        $foreignCustomer = $this->customerFor($otherBusiness);

        $paidInvoice = $this->invoiceFor($business, $customer, $user, 1000);
        $overdue = $this->invoiceFor($business, $second, $user, 400, ['due_date' => today()->subDays(45)->toDateString()]);
        $this->invoiceFor($business, $customer, $user, 100, ['status' => 'draft']);
        $this->invoiceFor($otherBusiness, $foreignCustomer, $otherUser, 9999);
        $this->actingAs($user)->postJson('/api/v1/payments', ['invoice_id' => $paidInvoice->id, 'amount' => 1000, 'payment_method' => 'bank_transfer'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Hi'])->assertCreated();

        $dashboard = $this->actingAs($user)->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertSame(2, $dashboard['metrics']['total_customers']);
        $this->assertSame(2, $dashboard['metrics']['total_invoices']);
        $this->assertEquals(1400, $dashboard['metrics']['total_sales']);
        $this->assertEquals(1000, $dashboard['metrics']['total_collections']);
        $this->assertEquals(400, $dashboard['metrics']['total_outstanding']);
        $this->assertEquals(400, $dashboard['metrics']['overdue_amount']);
        $this->assertCount(6, $dashboard['revenue_trend']);
        $this->assertEquals(400, $dashboard['ageing']['31_60_days']);
        $this->assertNotEmpty($dashboard['recent_payments']);
        $this->assertNotEmpty($dashboard['recent_activity']);

        $sales = $this->actingAs($user)->getJson('/api/v1/reports/sales?from_date=' . today()->subDay()->toDateString() . '&to_date=' . today()->toDateString())->assertOk()->json('data');
        $this->assertEquals(1400, $sales['total_sales']);
        $this->assertSame(1, $sales['paid_invoices']);
        $this->assertSame(1, $sales['outstanding_invoices']);
        $this->assertSame(0, $this->actingAs($user)->getJson('/api/v1/reports/sales?from_date=2000-01-01&to_date=2000-01-02')->json('data.invoice_count'));
        $this->actingAs($user)->getJson('/api/v1/reports/sales?from_date=2020-02-01&to_date=2020-01-01')->assertUnprocessable();

        $customers = $this->actingAs($user)->getJson('/api/v1/reports/customers')->assertOk()->json('data');
        $this->assertSame(2, $customers['total_customers']);
        $this->assertSame('Top', $customers['top_customers'][0]['name']);
        $this->assertSame(1, $customers['customers_with_debt']);

        $debt = $this->actingAs($user)->getJson('/api/v1/reports/debt')->assertOk()->json('data');
        $this->assertEquals(400, $debt['total_outstanding']);
        $this->assertEquals(400, $debt['overdue_amount']);
        $this->assertSame($second->id, $debt['customer_balances'][0]['customer_id']);
        $this->assertSame(45, $debt['invoices'][0]['days_overdue']);

        $messaging = $this->actingAs($user)->getJson('/api/v1/reports/messaging')->assertOk()->json('data');
        $this->assertSame(1, $messaging['messages_sent']);
        $this->assertSame(1, $messaging['queued']);

        // The other tenant sees only its own numbers.
        $this->assertEquals(9999, $this->actingAs($otherUser)->getJson('/api/v1/dashboard')->json('data.metrics.total_sales'));
        $this->assertSame(0, $this->actingAs($otherUser)->getJson('/api/v1/reports/messaging')->json('data.messages_sent'));
    }

    public function test_notifications_are_per_business_and_can_be_read(): void
    {
        [$user, $business] = $this->membership();
        [$otherUser] = $this->membership('Other');
        $customer = $this->customerFor($business);
        $invoice = $this->invoiceFor($business, $customer, $user, 10);
        $this->actingAs($user)->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 10, 'payment_method' => 'cash'])->assertCreated();

        $list = $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread_count', 1)->json('data.data');
        $this->assertSame('payment_received', $list[0]['type']);
        $this->actingAs($otherUser)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($otherUser)->postJson("/api/v1/notifications/{$list[0]['id']}/read")->assertNotFound();

        $this->actingAs($user)->postJson("/api/v1/notifications/{$list[0]['id']}/read")->assertOk();
        $this->actingAs($user)->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->actingAs($user)->postJson('/api/v1/notifications/read-all')->assertOk();
    }

    public function test_audit_logs_search_and_business_profile(): void
    {
        [$user, $business] = $this->membership();
        [$otherUser] = $this->membership('Other');
        $customer = $this->actingAs($user)->postJson('/api/v1/customers', ['name' => 'Audit Me', 'phone' => '+1 555 0100'])->assertCreated()->json('data');

        $logs = $this->actingAs($user)->getJson('/api/v1/audit-logs?action=customer')->assertOk()->json('data.data');
        $this->assertSame('customer.created', $logs[0]['action']);
        $this->assertSame($user->id, $logs[0]['user_id']);
        $this->actingAs($otherUser)->getJson('/api/v1/audit-logs')->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($otherUser)->getJson("/api/v1/audit-logs/{$logs[0]['id']}")->assertNotFound();

        $search = $this->actingAs($user)->getJson('/api/v1/search?q=audit')->assertOk()->json('data');
        $this->assertCount(1, $search['customers']);
        $this->assertSame($customer['id'], $search['customers'][0]['id']);
        $this->assertCount(0, $this->actingAs($otherUser)->getJson('/api/v1/search?q=audit')->json('data.customers'));

        $profile = $this->actingAs($user)->getJson('/api/v1/business')->assertOk()->json('data');
        $this->assertSame('owner', $profile['role']);
        $this->assertFalse($profile['whatsapp_connected']);
        $this->actingAs($user)->putJson('/api/v1/business', ['currency' => 'ngn', 'name' => 'Renamed'])->assertOk()->assertJsonPath('data.currency', 'NGN');
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.updated', 'entity_id' => $business->id]);
    }
}
