<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompletionTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = DB::table('companies')->insertGetId(['name' => 'Test complet', 'plan' => 'Business', 'subscription_status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['company_id' => $company, 'user_id' => $user->id, 'role' => $role, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $client = DB::table('contacts')->insertGetId(['company_id' => $company, 'type' => 'client', 'name' => 'Client', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->withSession(['company_id' => $company]);

        return [$user, $company, $client];
    }

    private function invoice(int $client, ?int $product = null, int $quantity = 1): int
    {
        return $this->postJson('/api/v1/invoices', ['contact_id' => $client, 'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(10)->toDateString(), 'tax_basis_points' => 1800, 'items' => [['description' => 'Article', 'quantity' => $quantity, 'unit_price' => 1000, 'product_id' => $product]]])->assertCreated()->json('id');
    }

    private function issue(int $id): void
    {
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertOk();
    }

    private function product(int $quantity): int
    {
        $id = $this->postJson('/api/v1/products', ['name' => 'Produit physique', 'type' => 'product', 'price' => 1000])->assertCreated()->json('id');
        if ($quantity > 0) {
            $this->postJson('/api/v1/stock', ['product_id' => $id, 'quantity' => $quantity, 'reason' => 'Stock initial'])->assertOk();
        }

        return $id;
    }

    private function entry(int $amount = 1000, string $account = '521', ?string $date = null): int
    {
        return $this->postJson('/api/v1/accounting/entries', ['entry_date' => $date ?? today()->toDateString(), 'label' => 'Encaissement', 'journal' => 'BQ', 'lines' => [['account_code' => $account, 'debit' => $amount, 'credit' => 0], ['account_code' => '411', 'debit' => 0, 'credit' => $amount]]])->assertCreated()->json('id');
    }

    private function statement(int $amount = 1000, string $reference = 'R-01'): int
    {
        return $this->postJson('/api/v1/banking', ['account_code' => '521', 'reference' => $reference, 'from' => today()->toDateString(), 'to' => today()->toDateString(), 'opening_balance' => 0, 'closing_balance' => $amount, 'lines' => [['booked_at' => today()->toDateString(), 'description' => 'Encaissement bancaire', 'amount' => $amount]]])->assertCreated()->json('id');
    }

    public function test_invoice_stock_is_deducted_only_at_final_validation_and_returns_are_limited(): void
    {
        [$user,$company,$client] = $this->workspace();
        $product = $this->product(5);
        $invoice = $this->invoice($client, $product, 3);
        $this->assertSame(5, (int) DB::table('stock_movements')->sum('quantity'));
        $this->issue($invoice);
        $this->assertSame(2, (int) DB::table('stock_movements')->sum('quantity'));
        $this->postJson("/api/v1/invoices/$invoice/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertUnprocessable();
        $payload = ['product_id' => $product, 'quantity' => 2, 'reason' => 'Retour reçu', 'request_id' => (string) Str::uuid()];
        $this->postJson("/api/v1/invoices/$invoice/returns", $payload)->assertOk();
        $this->postJson("/api/v1/invoices/$invoice/returns", $payload)->assertOk();
        $this->assertSame(4, (int) DB::table('stock_movements')->sum('quantity'));
        $this->postJson("/api/v1/invoices/$invoice/returns", [...$payload, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson("/api/v1/invoices/$invoice/returns", [...$payload, 'quantity' => 1])->assertConflict();
    }

    public function test_insufficient_stock_rolls_back_approval_number_and_accounting(): void
    {
        [$user,$company,$client] = $this->workspace();
        $product = $this->product(1);
        $invoice = $this->invoice($client, $product, 2);
        $this->postJson("/api/v1/invoices/$invoice/transition", ['action' => 'submit'])->assertOk();
        $this->postJson("/api/v1/invoices/$invoice/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertUnprocessable();
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'status' => 'pending', 'number' => null]);
        $this->assertDatabaseCount('invoice_approvals', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame(0, DB::table('companies')->find($company)->invoice_sequence);
    }

    public function test_product_links_are_scoped_and_repeated_lines_are_aggregated(): void
    {
        [$foreign,$foreignCompany] = $this->workspace();
        $foreignProduct = $this->product(5);
        [$user,$company,$client] = $this->workspace();
        $payload = ['contact_id' => $client, 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'tax_basis_points' => 0, 'items' => [['description' => 'Article', 'quantity' => 1, 'unit_price' => 1000, 'product_id' => $foreignProduct]]];
        $this->postJson('/api/v1/invoices', $payload)->assertUnprocessable();
        $product = $this->product(3);
        $payload['items'] = [['description' => 'Article A', 'quantity' => 2, 'unit_price' => 1000, 'product_id' => $product], ['description' => 'Article B', 'quantity' => 2, 'unit_price' => 1000, 'product_id' => $product]];
        $id = $this->postJson('/api/v1/invoices', $payload)->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertUnprocessable();
    }

    public function test_purchase_receipt_increases_stock_once(): void
    {
        [$user,$company] = $this->workspace();
        $product = $this->product(0);
        $supplier = DB::table('contacts')->insertGetId(['company_id' => $company, 'type' => 'supplier', 'name' => 'Fournisseur', 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['supplier_id' => $supplier, 'reference' => 'ACH-01', 'description' => 'Réception', 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'subtotal' => 4000, 'tax_amount' => 0, 'account_code' => '601', 'product_id' => $product, 'quantity' => 4];
        $id = $this->postJson('/api/v1/purchases', $payload)->assertCreated()->json('id');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson("/api/v1/purchases/$id/approve")->assertOk();
        $this->postJson("/api/v1/purchases/$id/approve")->assertUnprocessable();
        $this->assertSame(4, (int) DB::table('stock_movements')->sum('quantity'));
    }

    public function test_paid_invoice_credit_and_refund_are_balanced_idempotent_and_capped(): void
    {
        [$user,$company,$client] = $this->workspace();
        $invoice = $this->invoice($client);
        $this->issue($invoice);
        $this->postJson("/api/v1/invoices/$invoice/payments", ['amount' => 1180, 'paid_at' => today()->toDateString(), 'method' => 'Virement', 'request_id' => (string) Str::uuid()])->assertOk();
        $refund = ['amount' => 590, 'paid_at' => today()->toDateString(), 'method' => 'Virement', 'reference' => 'BANK-REFUND-1', 'request_id' => (string) Str::uuid()];
        $this->postJson("/api/v1/invoices/$invoice/refunds", $refund)->assertUnprocessable();
        $this->postJson("/api/v1/invoices/$invoice/credits", ['total' => 590, 'reason' => 'Retour partiel', 'issued_at' => today()->toDateString(), 'include_paid' => true])->assertCreated();
        $this->getJson('/api/v1/business')->assertJsonPath('invoices.0.refund_due', 590)->assertJsonPath('invoices.0.status', 'paid');
        $this->postJson("/api/v1/invoices/$invoice/refunds", [...$refund, 'amount' => 591])->assertUnprocessable();
        $this->postJson("/api/v1/invoices/$invoice/refunds", $refund)->assertOk();
        $this->postJson("/api/v1/invoices/$invoice/refunds", $refund)->assertOk();
        $this->getJson('/api/v1/business')->assertJsonPath('invoices.0.refund_due', 0)->assertJsonPath('invoices.0.refunded', 590);
        $this->postJson("/api/v1/invoices/$invoice/refunds", [...$refund, 'amount' => 1])->assertConflict();
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame((int) DB::table('journal_lines')->sum('debit'), (int) DB::table('journal_lines')->sum('credit'));
        $customer = DB::table('journal_lines')->where('account_code', '411');
        $this->assertSame((int) (clone $customer)->sum('debit'), (int) $customer->sum('credit'));
    }

    public function test_archived_drafts_can_be_restored_and_do_not_block_closing(): void
    {
        [$user,$company,$client] = $this->workspace();
        $invoice = $this->invoice($client);
        $this->putJson("/api/v1/invoices/$invoice/archive", ['archived' => true])->assertOk();
        $this->postJson("/api/v1/invoices/$invoice/transition", ['action' => 'submit'])->assertUnprocessable();
        $this->postJson('/api/v1/accounting/close', ['through' => today()->toDateString()])->assertOk();
        $this->putJson("/api/v1/invoices/$invoice/archive", ['archived' => false])->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'archived' => false]);
    }

    public function test_bank_import_checks_balances_dates_and_duplicate_periods(): void
    {
        $this->workspace();
        $this->getJson('/api/v1/banking')->assertOk();
        $payload = ['account_code' => '521', 'reference' => 'BANK', 'from' => today()->toDateString(), 'to' => today()->toDateString(), 'opening_balance' => 0, 'closing_balance' => 999, 'lines' => [['booked_at' => today()->toDateString(), 'description' => 'Test', 'amount' => 1000]]];
        $this->postJson('/api/v1/banking', $payload)->assertUnprocessable();
        $payload['closing_balance'] = 1000;
        $this->postJson('/api/v1/banking', $payload)->assertCreated();
        $this->postJson('/api/v1/banking', [...$payload, 'reference' => 'OVERLAP'])->assertUnprocessable();
        $payload['lines'][0]['booked_at'] = today()->subDay()->toDateString();
        $this->postJson('/api/v1/banking', $payload)->assertUnprocessable();
    }

    public function test_bank_matching_and_finalization_lock_history_and_allow_future_entries(): void
    {
        $this->workspace();
        $entry = $this->entry();
        $statement = $this->statement();
        $line = DB::table('bank_statement_lines')->where('statement_id', $statement)->value('id');
        $journal = DB::table('journal_lines')->where('entry_id', $entry)->where('account_code', '521')->value('id');
        $this->postJson("/api/v1/banking/$statement/reconcile")->assertUnprocessable();
        $this->putJson("/api/v1/banking/$statement/lines/$line", ['journal_line_id' => $journal])->assertOk();
        $this->postJson("/api/v1/banking/$statement/reconcile")->assertOk();
        $this->putJson("/api/v1/banking/$statement/lines/$line", ['journal_line_id' => null])->assertUnprocessable();
        $this->postJson("/api/v1/banking/$statement/reconcile")->assertConflict();
        $this->postJson('/api/v1/accounting/entries', ['entry_date' => today()->toDateString(), 'label' => 'Rétroactif', 'journal' => 'BQ', 'lines' => [['account_code' => '521', 'debit' => 1, 'credit' => 0], ['account_code' => '411', 'debit' => 0, 'credit' => 1]]])->assertUnprocessable();
        $this->travel(1)->days();
        $this->entry(20);
        $this->travelBack();
    }

    public function test_bank_matching_rejects_foreign_accounts_and_wrong_amounts(): void
    {
        [$foreign,$foreignCompany] = $this->workspace();
        $foreignEntry = $this->entry();
        $foreignLine = DB::table('journal_lines')->where('entry_id', $foreignEntry)->where('account_code', '521')->value('id');
        [$user,$company] = $this->workspace();
        $entry = $this->entry(500);
        $statement = $this->statement();
        $line = DB::table('bank_statement_lines')->where('statement_id', $statement)->value('id');
        $ownLine = DB::table('journal_lines')->where('entry_id', $entry)->where('account_code', '521')->value('id');
        $this->putJson("/api/v1/banking/$statement/lines/$line", ['journal_line_id' => $foreignLine])->assertUnprocessable();
        $this->putJson("/api/v1/banking/$statement/lines/$line", ['journal_line_id' => $ownLine])->assertUnprocessable();
        $this->actingAs($foreign)->withSession(['company_id' => $foreignCompany]);
        $this->putJson("/api/v1/banking/$statement/lines/$line", ['journal_line_id' => null])->assertNotFound();
        $this->getJson('/api/v1/banking')->assertJsonCount(0, 'statements');
    }

    public function test_cancelling_a_draft_releases_associations_and_allows_a_replacement_period(): void
    {
        $this->workspace();
        $entry = $this->entry();
        $statement = $this->statement();
        $line = DB::table('bank_statement_lines')->where('statement_id', $statement)->value('id');
        $journal = DB::table('journal_lines')->where('entry_id', $entry)->where('account_code', '521')->value('id');
        $this->putJson("/api/v1/banking/$statement/lines/$line", ['journal_line_id' => $journal])->assertOk();
        $this->postJson("/api/v1/banking/$statement/cancel")->assertOk();
        $this->assertDatabaseHas('bank_statements', ['id' => $statement, 'status' => 'cancelled']);
        $this->assertDatabaseHas('bank_statement_lines', ['id' => $line, 'journal_line_id' => null]);
        $this->postJson("/api/v1/banking/$statement/reconcile")->assertConflict();
        $this->statement(1000, 'R-02');
    }

    public function test_refunds_and_returns_of_another_company_are_unreachable(): void
    {
        [$owner,$company,$client] = $this->workspace();
        $invoice = $this->invoice($client);
        $this->issue($invoice);
        $this->workspace();
        $this->postJson("/api/v1/invoices/$invoice/refunds", ['amount' => 1, 'paid_at' => today()->toDateString(), 'method' => 'Virement', 'reference' => 'TEST', 'request_id' => (string) Str::uuid()])->assertNotFound();
        $this->postJson("/api/v1/invoices/$invoice/returns", ['quantity' => 1, 'product_id' => 1, 'reason' => 'Retour', 'request_id' => (string) Str::uuid()])->assertNotFound();
        $this->putJson("/api/v1/invoices/$invoice/archive", ['archived' => true])->assertNotFound();
    }

    public function test_permissions_deny_refunds_and_bank_writes_to_viewers(): void
    {
        $this->workspace('viewer');
        $this->getJson('/api/v1/refunds')->assertForbidden();
        $this->getJson('/api/v1/banking')->assertForbidden();
        $this->postJson('/api/v1/banking', [])->assertForbidden();
        $this->postJson('/api/v1/invoices/1/refunds', [])->assertForbidden();
    }
}
