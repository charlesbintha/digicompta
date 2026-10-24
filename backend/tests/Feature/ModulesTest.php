<?php

namespace Tests\Feature;

use App\Jobs\DeliverMessage;
use App\Models\User;
use App\Support\Communications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $role = 'owner'): array
    {
        $u = User::factory()->create();
        $c = DB::table('companies')->insertGetId(['name' => 'Entreprise test', 'plan' => 'Business', 'subscription_status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['company_id' => $c, 'user_id' => $u->id, 'role' => $role, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $contact = DB::table('contacts')->insertGetId(['company_id' => $c, 'type' => 'client', 'name' => 'Client', 'email' => 'client@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($u)->withSession(['company_id' => $c]);

        return [$u, $c, $contact];
    }

    private function invoice(int $contact, bool $approve = true): int
    {
        $id = $this->postJson('/api/v1/invoices', ['contact_id' => $contact, 'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(10)->toDateString(), 'tax_basis_points' => 1800, 'items' => [['description' => 'Prestation', 'quantity' => 1, 'unit_price' => 10000]]])->assertCreated()->json('id');
        if ($approve) {
            $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
            $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertOk();
        }

        return $id;
    }

    private function entry(array $overrides = []): array
    {
        return [...['entry_date' => today()->toDateString(), 'label' => 'Apport', 'journal' => 'OD', 'lines' => [['account_code' => '521', 'debit' => 1000, 'credit' => 0], ['account_code' => '411', 'debit' => 0, 'credit' => 1000]]], ...$overrides];
    }

    public function test_ledger_balances_automatic_invoice_payment_credit_and_import_is_idempotent(): void
    {
        [$u,$c,$client] = $this->workspace();
        $id = $this->invoice($client);
        $this->postJson("/api/v1/invoices/$id/credits", ['total' => 1800, 'reason' => 'Remise commerciale', 'issued_at' => today()->toDateString()])->assertCreated();
        $pay = ['amount' => 10000, 'paid_at' => today()->toDateString(), 'method' => 'Virement', 'request_id' => (string) Str::uuid()];
        $this->postJson("/api/v1/invoices/$id/payments", $pay)->assertOk();
        $this->postJson("/api/v1/invoices/$id/payments", $pay)->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $id, 'credited' => 1800, 'status' => 'paid']);
        $this->postJson('/api/v1/accounting/sync')->assertOk();
        $this->postJson('/api/v1/accounting/sync')->assertOk();
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertSame((int) DB::table('journal_lines')->sum('debit'), (int) DB::table('journal_lines')->sum('credit'));
        $entries = $this->getJson('/api/v1/accounting')->assertOk()->json('entries');
        foreach ($entries as $e) {
            $this->assertSame(array_sum(array_column($e['lines'], 'debit')), array_sum(array_column($e['lines'], 'credit')));
        }
        $this->postJson("/api/v1/invoices/$id/credits", ['total' => 1, 'reason' => 'Trop', 'issued_at' => today()->toDateString()])->assertUnprocessable();
    }

    public function test_manual_entries_require_balance_and_extourne_and_closed_periods_are_locked(): void
    {
        $this->workspace();
        $bad = $this->entry();
        $bad['lines'][1]['credit'] = 999;
        $this->postJson('/api/v1/accounting/entries', $bad)->assertUnprocessable();
        $id = $this->postJson('/api/v1/accounting/entries', $this->entry())->assertCreated()->json('id');
        $this->postJson("/api/v1/accounting/entries/$id/reverse", ['entry_date' => today()->toDateString(), 'reason' => 'Erreur de saisie'])->assertOk();
        $this->postJson("/api/v1/accounting/entries/$id/reverse", ['entry_date' => today()->toDateString(), 'reason' => 'Erreur de saisie'])->assertUnprocessable();
        $this->postJson('/api/v1/accounting/close', ['through' => today()->toDateString()])->assertOk();
        $this->postJson('/api/v1/accounting/entries', $this->entry())->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_close_refuses_pending_operations_and_does_not_partially_post_history(): void
    {
        [$u,$c,$client] = $this->workspace();
        $this->invoice($client, false);
        $this->postJson('/api/v1/accounting/close', ['through' => today()->toDateString()])->assertUnprocessable();
        $this->assertNull(DB::table('companies')->find($c)->closed_through);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_quotes_convert_once_and_enforce_customer_scope(): void
    {
        [$other,$otherCompany,$foreign] = $this->workspace();
        [$u,$c,$client] = $this->workspace();
        $payload = ['contact_id' => $client, 'valid_until' => today()->addDay()->toDateString(), 'tax_basis_points' => 1800, 'items' => [['description' => 'Article', 'quantity' => 2, 'unit_price' => 500]]];
        $this->postJson('/api/v1/quotes', [...$payload, 'contact_id' => $foreign])->assertUnprocessable();
        $id = $this->postJson('/api/v1/quotes', $payload)->assertCreated()->json('id');
        $this->postJson("/api/v1/quotes/$id/action", ['action' => 'convert'])->assertUnprocessable();
        $this->postJson("/api/v1/quotes/$id/action", ['action' => 'accept'])->assertOk();
        $invoice = $this->postJson("/api/v1/quotes/$id/action", ['action' => 'convert'])->assertOk()->json('invoice_id');
        $this->postJson("/api/v1/quotes/$id/action", ['action' => 'convert'])->assertOk()->assertJsonPath('invoice_id', $invoice);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'total' => 1180, 'status' => 'draft']);
        $this->actingAs($other)->withSession(['company_id' => $otherCompany])->postJson("/api/v1/quotes/$id/action", ['action' => 'decline'])->assertNotFound();
    }

    public function test_purchase_approval_and_payments_post_once_and_reject_overpayment(): void
    {
        [$u,$c] = $this->workspace();
        $supplier = DB::table('contacts')->insertGetId(['company_id' => $c, 'type' => 'supplier', 'name' => 'Fournisseur', 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->postJson('/api/v1/purchases', ['supplier_id' => $supplier, 'reference' => 'F-01', 'description' => 'Marchandises', 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'subtotal' => 1000, 'tax_amount' => 180, 'account_code' => '601'])->assertCreated()->json('id');
        $pay = ['amount' => 1180, 'method' => 'Espèces', 'paid_at' => today()->toDateString(), 'request_id' => (string) Str::uuid()];
        $this->postJson("/api/v1/purchases/$id/payments", $pay)->assertUnprocessable();
        $this->postJson("/api/v1/purchases/$id/approve")->assertOk();
        $this->postJson("/api/v1/purchases/$id/payments", [...$pay, 'amount' => 1181])->assertUnprocessable();
        $this->postJson("/api/v1/purchases/$id/payments", $pay)->assertOk();
        $this->postJson("/api/v1/purchases/$id/payments", $pay)->assertOk();
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('purchase_payments', 1);
        $this->assertDatabaseHas('purchases', ['id' => $id, 'status' => 'paid']);
    }

    public function test_expenses_require_validation_and_stock_cannot_be_negative_or_cross_tenant(): void
    {
        [$u,$c] = $this->workspace();
        $expense = $this->postJson('/api/v1/expenses', ['description' => 'Transport', 'category' => 'Déplacement', 'amount' => 1000, 'paid_at' => today()->toDateString()])->assertCreated()->json('id');
        $this->assertDatabaseCount('journal_entries', 0);
        $this->postJson("/api/v1/expenses/$expense/decision", ['action' => 'approve'])->assertOk();
        $this->assertDatabaseCount('journal_entries', 1);
        $product = $this->postJson('/api/v1/products', ['name' => 'Cahier', 'type' => 'product', 'price' => 500])->assertCreated()->json('id');
        $this->postJson('/api/v1/stock', ['product_id' => $product, 'quantity' => 10, 'reason' => 'Stock initial'])->assertOk();
        $this->postJson('/api/v1/stock', ['product_id' => $product, 'quantity' => -11, 'reason' => 'Sortie excessive'])->assertUnprocessable();
        $this->putJson("/api/v1/records/products/$product", ['name' => 'Cahier', 'type' => 'service', 'price' => 500, 'archived' => false])->assertUnprocessable();
        $this->workspace();
        $this->postJson('/api/v1/stock', ['product_id' => $product, 'quantity' => 1, 'reason' => 'Intrusion'])->assertUnprocessable();
    }

    public function test_plan_modules_quotas_and_expiry_are_enforced_and_billing_remains_accessible(): void
    {
        [$u,$c,$client] = $this->workspace();
        DB::table('plans')->where('name', 'Business')->update(['monthly_invoices' => 1, 'max_users' => 1, 'modules' => '[]']);
        $this->getJson('/api/v1/accounting')->assertForbidden();
        $this->postJson('/api/v1/quotes', [])->assertForbidden();
        $this->invoice($client, false);
        $this->postJson('/api/v1/invoices', ['contact_id' => $client, 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'tax_basis_points' => 0, 'items' => [['description' => 'Trop', 'quantity' => 1, 'unit_price' => 1]]])->assertUnprocessable();
        $this->postJson('/api/v1/members', ['name' => 'Équipier', 'email' => 'm@example.test', 'password' => 'Long-password-2026', 'role' => 'sales'])->assertUnprocessable();
        DB::table('companies')->where('id', $c)->update(['subscription_ends_at' => now()->subMinute()]);
        $this->postJson('/api/v1/products', ['name' => 'Interdit', 'type' => 'product', 'price' => 1])->assertForbidden();
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('active', false);
        DB::table('companies')->where('id', $c)->update(['subscription_status' => 'suspended']);
        $this->getJson('/api/v1/business')->assertForbidden();
        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/subscription')->assertOk();
        $this->getJson('/api/v1/support')->assertOk();
    }

    public function test_subscription_promotions_reserve_cancel_and_confirm_without_double_extension(): void
    {
        [$u,$c] = $this->workspace();
        $plan = DB::table('plans')->where('name', 'Business')->first();
        DB::table('plans')->where('id', $plan->id)->update(['monthly_price' => 10001, 'active' => true]);
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->postJson('/api/v1/admin/promotions', [])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/admin/promotions', ['code' => 'TEST10', 'type' => 'percent', 'value' => 10, 'plan_id' => $plan->id, 'max_uses' => 1, 'active' => true])->assertOk();
        $this->actingAs($u);
        $id = $this->postJson('/api/v1/subscription/orders', ['plan_id' => $plan->id, 'code' => 'test10'])->assertCreated()->json('id');
        $this->assertDatabaseHas('subscription_orders', ['id' => $id, 'discount' => 1000, 'total' => 9001, 'status' => 'pending']);
        $this->postJson('/api/v1/subscription/orders', ['plan_id' => $plan->id])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/v1/admin/orders/$id/decision", ['action' => 'cancel'])->assertOk();
        $this->assertDatabaseHas('promotions', ['code' => 'TEST10', 'uses' => 0]);
        $this->actingAs($u);
        $id = $this->postJson('/api/v1/subscription/orders', ['plan_id' => $plan->id, 'code' => 'TEST10'])->assertCreated()->json('id');
        $this->actingAs($admin)->postJson("/api/v1/admin/orders/$id/decision", ['action' => 'confirm', 'payment_reference' => 'TEST-RECU'])->assertOk();
        $end = DB::table('companies')->find($c)->subscription_ends_at;
        $this->postJson("/api/v1/admin/orders/$id/decision", ['action' => 'confirm', 'payment_reference' => 'TEST-RECU'])->assertConflict();
        $this->assertSame($end, DB::table('companies')->find($c)->subscription_ends_at);
        $this->assertDatabaseHas('subscription_orders', ['id' => $id, 'status' => 'paid']);
        $this->assertDatabaseCount('user_notifications', 2);
    }

    public function test_support_sla_is_snapshotted_and_replies_are_scoped(): void
    {
        [$u,$c] = $this->workspace();
        $id = $this->postJson('/api/v1/support', ['subject' => 'Un souci', 'body' => 'Description détaillée du bug.', 'category' => 'bug', 'priority' => 'high'])->assertCreated()->json('id');
        $due = DB::table('support_tickets')->find($id)->response_due_at;
        DB::table('plans')->where('name', 'Business')->update(['sla_response_hours' => 1]);
        $this->assertSame($due, DB::table('support_tickets')->find($id)->response_due_at);
        [$other,$otherCompany] = $this->workspace();
        $this->postJson("/api/v1/support/$id/replies", ['body' => 'Intrusion'])->assertNotFound();
        $this->getJson('/api/v1/support')->assertJsonCount(0, 'tickets');
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->postJson("/api/v1/admin/support/$id/replies", ['body' => 'Nous analysons votre signalement.'])->assertOk();
        $this->assertNotNull(DB::table('support_tickets')->find($id)->responded_at);
        $this->assertDatabaseHas('user_notifications', ['company_id' => $c, 'user_id' => $u->id]);
    }

    public function test_sla_command_does_not_duplicate_alerts(): void
    {
        [$u,$c] = $this->workspace();
        $id = $this->postJson('/api/v1/support', ['subject' => 'Incident', 'body' => 'Description détaillée du bug.', 'category' => 'bug', 'priority' => 'normal'])->assertCreated()->json('id');
        DB::table('support_tickets')->where('id', $id)->update(['response_due_at' => now()->subMinute()]);
        $this->artisan('digi:check-sla')->assertSuccessful();
        $this->artisan('digi:check-sla')->assertSuccessful();
        $this->assertDatabaseCount('user_notifications', 1);
    }

    public function test_smtp_secret_is_encrypted_hidden_and_private_hosts_are_rejected(): void
    {
        Queue::fake();
        [$u,$c] = $this->workspace();
        $smtp = ['host' => '8.8.8.8', 'port' => 587, 'encryption' => 'tls', 'username' => 'test-user', 'password' => 'TestSecret-only', 'from_email' => 'test@example.test', 'from_name' => 'Test', 'enabled' => true];
        $this->putJson('/api/v1/communications/smtp', [...$smtp, 'host' => '127.0.0.1'])->assertUnprocessable();
        $this->putJson('/api/v1/communications/smtp', $smtp)->assertOk();
        $stored = DB::table('smtp_settings')->first();
        $this->assertNotSame('TestSecret-only', $stored->password);
        $this->assertSame('TestSecret-only', Crypt::decryptString($stored->password));
        $this->getJson('/api/v1/communications')->assertOk()->assertJsonMissingPath('smtp.password')->assertJsonPath('smtp.has_password', true);
        $this->postJson('/api/v1/communications/send', ['test' => true])->assertStatus(202);
        Queue::assertPushed(DeliverMessage::class);
        $this->assertDatabaseHas('message_outbox', ['company_id' => $c, 'recipient' => $u->email, 'status' => 'queued']);
    }

    public function test_delivery_records_sanitized_failure_without_leaking_credentials(): void
    {
        Queue::fake();
        [$u,$c] = $this->workspace();
        $id = Communications::queue($c, $u->id, 'mail', 'test@example.test', 'Test', 'Test');
        $transport = \Mockery::mock(Communications::class);
        $transport->shouldReceive('deliver')->once()->andThrow(new \RuntimeException('secret-password-sensitive'));
        (new DeliverMessage($id))->handle($transport);
        $row = DB::table('message_outbox')->find($id);
        $this->assertSame('failed', $row->status);
        $this->assertStringNotContainsString('secret', $row->error);
    }

    public function test_push_and_notifications_are_private_and_endpoints_are_restricted(): void
    {
        [$u,$c] = $this->workspace();
        config(['digi.vapid.public_key' => 'test', 'digi.vapid.private_key' => 'test']);
        $payload = ['endpoint' => 'https://localhost/test', 'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('A', 22)]];
        $this->postJson('/api/v1/push', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/push', [...$payload, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/example'])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertStringNotContainsString('fcm.googleapis.com', DB::table('push_subscriptions')->first()->payload);
        DB::table('user_notifications')->insert(['company_id' => $c, 'user_id' => $u->id, 'title' => 'Privé', 'body' => 'Test', 'created_at' => now()]);
        $this->workspace();
        $this->getJson('/api/v1/notifications')->assertJsonCount(0, 'notifications');
        $this->postJson('/api/v1/notifications/read')->assertOk();
        $this->assertNull(DB::table('user_notifications')->first()->read_at);
    }

    public function test_accounting_and_business_records_cannot_cross_tenants(): void
    {
        [$u,$c,$client] = $this->workspace();
        $id = $this->postJson('/api/v1/accounting/entries', $this->entry())->assertCreated()->json('id');
        $this->workspace();
        $this->getJson('/api/v1/accounting')->assertJsonCount(0, 'entries');
        $this->postJson("/api/v1/accounting/entries/$id/reverse", ['entry_date' => today()->toDateString(), 'reason' => 'Intrusion'])->assertNotFound();
        $this->putJson("/api/v1/records/contacts/$client", ['name' => 'Intrus', 'archived' => true])->assertNotFound();
    }
}
