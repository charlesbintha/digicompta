<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = DB::table('companies')->insertGetId(['name' => 'Entreprise test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['company_id' => $company, 'user_id' => $user->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $client = DB::table('contacts')->insertGetId(['company_id' => $company, 'type' => 'client', 'name' => 'Client test', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $company, $client];
    }

    private function signIn(User $user, int $company): void
    {
        $this->actingAs($user)->withSession(['company_id' => $company]);
    }

    private function invoicePayload(int $client): array
    {
        return ['contact_id' => $client, 'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(15)->toDateString(),
            'tax_basis_points' => 1800, 'items' => [['description' => 'Service', 'quantity' => 3, 'unit_price' => 10001]]];
    }

    public function test_registration_creates_a_senegalese_workspace_without_elevating_privileges(): void
    {
        $this->postJson('/api/v1/register', ['name' => 'Awa', 'company' => 'Keur Test', 'email' => 'awa@example.test',
            'password' => 'Long-password-2026', 'password_confirmation' => 'Long-password-2026', 'is_super_admin' => true])
            ->assertOk()->assertJsonPath('company.currency', 'XOF')->assertJsonPath('company.country', 'SN')->assertJsonPath('role', 'owner');
        $this->assertFalse((bool) User::first()->is_super_admin);
        $this->assertDatabaseCount('memberships', 1);
    }

    public function test_unauthenticated_and_invalid_session_access_is_denied(): void
    {
        $this->getJson('/api/v1/business')->assertUnauthorized();
        [$user,$company] = $this->workspace();
        $this->actingAs($user)->withSession(['company_id' => $company + 999])->getJson('/api/v1/business')->assertForbidden();
    }

    public function test_company_data_and_invoice_mutations_are_isolated(): void
    {
        [$userA,$companyA,$clientA] = $this->workspace();
        [$userB,$companyB,$clientB] = $this->workspace();
        $this->signIn($userB, $companyB);
        $id = $this->postJson('/api/v1/invoices', $this->invoicePayload($clientB))->assertCreated()->json('id');
        $this->signIn($userA, $companyA);
        $this->getJson('/api/v1/business')->assertOk()->assertJsonCount(1, 'contacts')->assertJsonCount(0, 'invoices')->assertJsonPath('contacts.0.id', $clientA);
        $this->postJson('/api/v1/invoices', $this->invoicePayload($clientB))->assertUnprocessable();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertNotFound();
        $this->postJson("/api/v1/invoices/$id/payments", ['amount' => 1, 'paid_at' => today()->toDateString(), 'method' => 'Virement', 'request_id' => (string) Str::uuid()])->assertNotFound();
        $this->getJson('/api/v1/admin/companies')->assertForbidden();
    }

    public function test_invoice_rounding_validation_and_payments_are_consistent_and_idempotent(): void
    {
        [$user,$company,$client] = $this->workspace();
        $this->signIn($user, $company);
        $id = $this->postJson('/api/v1/invoices', $this->invoicePayload($client))->assertCreated()->json('id');
        $this->assertDatabaseHas('invoices', ['id' => $id, 'subtotal' => 30003, 'tax_amount' => 5401, 'total' => 35404, 'number' => null]);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertUnprocessable();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertOk();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertUnprocessable();
        $payment = ['amount' => 10000, 'paid_at' => today()->toDateString(), 'method' => 'Wave', 'request_id' => (string) Str::uuid()];
        $this->postJson("/api/v1/invoices/$id/payments", $payment)->assertOk();
        $this->postJson("/api/v1/invoices/$id/payments", $payment)->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->postJson("/api/v1/invoices/$id/payments", [...$payment, 'amount' => 9999])->assertConflict();
        $this->postJson("/api/v1/invoices/$id/payments", [...$payment, 'amount' => 25405, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson("/api/v1/invoices/$id/payments", [...$payment, 'amount' => 25404, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'paid', 'number' => 'FAC-'.today()->year.'-00001']);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('audit_logs', 5);
    }

    public function test_sales_cannot_approve_and_viewers_cannot_write(): void
    {
        [$user,$company,$client] = $this->workspace('sales');
        $this->signIn($user, $company);
        $id = $this->postJson('/api/v1/invoices', $this->invoicePayload($client))->assertCreated()->json('id');
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertForbidden();
        $this->putJson('/api/v1/settings', ['name' => 'Intrusion'])->assertForbidden();
        $this->postJson('/api/v1/members', [])->assertForbidden();
        DB::table('memberships')->where('user_id', $user->id)->update(['role' => 'viewer']);
        $this->postJson('/api/v1/contacts', ['name' => 'Test', 'type' => 'client'])->assertForbidden();
        $this->postJson('/api/v1/products', [])->assertForbidden();
        $this->postJson('/api/v1/expenses', [])->assertForbidden();
        $this->postJson('/api/v1/invoices', $this->invoicePayload($client))->assertForbidden();
        $this->getJson('/api/v1/business')->assertOk();
    }

    public function test_subscription_suspension_is_enforced_and_admin_changes_are_audited(): void
    {
        [$user,$company] = $this->workspace();
        [$admin,$adminCompany] = $this->workspace();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->signIn($admin, $adminCompany);
        $this->putJson("/api/v1/admin/companies/$company", ['plan' => 'Business', 'subscription_status' => 'suspended'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company, 'user_id' => $admin->id, 'action' => 'Abonnement modifié par la plateforme']);
        $this->signIn($user, $company);
        $this->getJson('/api/v1/business')->assertForbidden();
        $this->postJson('/api/v1/contacts', ['type' => 'client', 'name' => 'Blocked'])->assertForbidden();
    }

    public function test_validation_rejects_fractional_xof_and_invalid_dates(): void
    {
        [$user,$company,$client] = $this->workspace();
        $this->signIn($user, $company);
        $payload = $this->invoicePayload($client);
        $payload['items'][0]['unit_price'] = 10.5;
        $this->postJson('/api/v1/invoices', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_price');
        $this->postJson('/api/v1/expenses', ['description' => 'Test', 'category' => 'Test', 'amount' => 100, 'paid_at' => today()->addDay()->toDateString()])->assertUnprocessable();
        $payload = $this->invoicePayload($client);
        $payload['items'][0]['unit_price'] = 0;
        $this->postJson('/api/v1/invoices', $payload)->assertUnprocessable();
        $payload['items'][0]['invoice_id'] = 999;
        $this->postJson('/api/v1/invoices', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_login_logout_and_wrong_password(): void
    {
        [$user] = $this->workspace();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/v1/me')->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();
        $this->assertGuest();
    }
}
