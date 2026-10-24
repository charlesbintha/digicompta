<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function company(): int
    {
        return DB::table('companies')->insertGetId(['name' => 'Entreprise test', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function member(int $company, string $role): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['company_id' => $company, 'user_id' => $user->id, 'role' => $role, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function login(User $user, int $company): void
    {
        $this->actingAs($user)->withSession(['company_id' => $company]);
    }

    private function customRole(int $company, array $permissions): string
    {
        $id = DB::table('company_roles')->insertGetId(['company_id' => $company, 'name' => 'Rôle '.uniqid(), 'permissions' => json_encode($permissions), 'created_at' => now(), 'updated_at' => now()]);

        return 'custom:'.$id;
    }

    private function draft(int $company, User $creator, int $total = 1000): int
    {
        $this->login($creator, $company);
        $contact = DB::table('contacts')->insertGetId(['company_id' => $company, 'type' => 'client', 'name' => 'Client', 'created_at' => now(), 'updated_at' => now()]);

        return $this->postJson('/api/v1/invoices', ['contact_id' => $contact, 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
            'tax_basis_points' => 0, 'items' => [['description' => 'Prestation', 'quantity' => 1, 'unit_price' => $total]]])->assertCreated()->json('id');
    }

    private function config(array $extra = []): array
    {
        return [...['enabled' => true, 'threshold' => 1000, 'prevent_self_approval' => true, 'distinct_approvers' => true,
            'steps' => [['role' => 'accountant', 'name' => 'Contrôle comptable'], ['role' => 'owner', 'name' => 'Accord direction']]], ...$extra];
    }

    public function test_custom_read_permissions_filter_payloads_and_restrict_mutations(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $this->draft($company, $owner);
        $role = $this->postJson('/api/v1/roles', ['name' => 'Répertoire uniquement', 'permissions' => ['contacts.read']])->assertCreated()->json('id');
        $reader = $this->member($company, 'custom:'.$role);
        $this->login($reader, $company);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('role_name', 'Répertoire uniquement')->assertJsonPath('permissions', ['contacts.read']);
        $this->getJson('/api/v1/business')->assertOk()->assertJsonCount(1, 'contacts')->assertJsonCount(0, 'invoices')->assertJsonCount(0, 'payments')->assertJsonCount(0, 'products')->assertJsonCount(0, 'expenses')->assertJsonCount(0, 'audit')->assertJsonCount(0, 'team');
        $this->getJson('/api/v1/access')->assertForbidden();
        $this->postJson('/api/v1/contacts', ['type' => 'client', 'name' => 'Interdit'])->assertForbidden();
        $this->postJson('/api/v1/roles', ['name' => 'Intrusion', 'permissions' => array_keys(Tenant::catalog())])->assertForbidden();
    }

    public function test_roles_require_known_permissions_and_read_dependencies(): void
    {
        $company = $this->company();
        $this->login($this->member($company, 'owner'), $company);
        $this->postJson('/api/v1/roles', ['name' => 'Inconnu', 'permissions' => ['platform.admin']])->assertUnprocessable();
        $this->postJson('/api/v1/roles', ['name' => 'Incomplet', 'permissions' => ['contacts.write']])->assertUnprocessable();
        $this->postJson('/api/v1/roles', ['name' => 'Valide', 'permissions' => ['contacts.read', 'contacts.write']])->assertCreated();
        $this->postJson('/api/v1/roles', ['name' => 'Valide', 'permissions' => []])->assertUnprocessable();
        $this->assertDatabaseCount('company_roles', 1);
    }

    public function test_access_manager_cannot_escalate_or_modify_more_privileged_members(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $accountant = $this->member($company, 'accountant');
        $managerKey = $this->customRole($company, ['team.read', 'team.write', 'roles.manage', 'contacts.read']);
        $manager = $this->member($company, $managerKey);
        $this->login($manager, $company);
        $this->postJson('/api/v1/roles', ['name' => 'Escalade', 'permissions' => ['invoices.read', 'invoices.approve']])->assertForbidden();
        $this->postJson('/api/v1/roles', ['name' => 'Répertoire', 'permissions' => ['contacts.read']])->assertCreated();
        $body = ['name' => 'Test', 'email' => 'new@example.test', 'password' => 'Long-password-2026', 'role' => 'accountant'];
        $this->postJson('/api/v1/members', $body)->assertForbidden();
        $this->postJson('/api/v1/members', [...$body, 'role' => 'owner'])->assertUnprocessable();
        $target = DB::table('memberships')->where('user_id', $accountant->id)->value('id');
        $this->putJson('/api/v1/members/'.$target, ['role' => $managerKey, 'is_active' => false])->assertForbidden();
        $this->putJson('/api/v1/roles/'.substr($managerKey, 7), ['name' => 'Moi', 'permissions' => ['team.read']])->assertUnprocessable();
        $this->assertDatabaseHas('memberships', ['user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);
    }

    public function test_company_roles_and_members_cannot_cross_tenant_boundaries(): void
    {
        $a = $this->company();
        $b = $this->company();
        $owner = $this->member($a, 'owner');
        $foreign = $this->member($b, 'accountant');
        $foreignRole = $this->customRole($b, ['contacts.read']);
        $this->login($owner, $a);
        $this->putJson('/api/v1/roles/'.substr($foreignRole, 7), ['name' => 'Vol', 'permissions' => []])->assertNotFound();
        $this->postJson('/api/v1/members', ['name' => 'Test', 'email' => 'test@example.test', 'password' => 'Long-password-2026', 'role' => $foreignRole])->assertUnprocessable();
        $id = DB::table('memberships')->where('user_id', $foreign->id)->value('id');
        $this->putJson('/api/v1/members/'.$id, ['role' => 'viewer', 'is_active' => false])->assertNotFound();
        $this->putJson('/api/v1/invoice-workflow', $this->config(['steps' => [['role' => $foreignRole, 'name' => 'Intrusion']]]))->assertUnprocessable();
    }

    public function test_disabling_a_member_revokes_existing_session_and_owner_is_protected(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $viewer = $this->member($company, 'viewer');
        $memberId = DB::table('memberships')->where('user_id', $viewer->id)->value('id');
        $ownerId = DB::table('memberships')->where('user_id', $owner->id)->value('id');
        $this->login($owner, $company);
        $this->putJson('/api/v1/members/'.$ownerId, ['role' => 'viewer', 'is_active' => false])->assertUnprocessable();
        $this->putJson('/api/v1/members/'.$memberId, ['role' => 'viewer', 'is_active' => false])->assertOk();
        $this->login($viewer, $company);
        $this->getJson('/api/v1/me')->assertForbidden();
        $this->getJson('/api/v1/business')->assertForbidden();
        $this->login($owner, $company);
        $this->putJson('/api/v1/members/'.$memberId, ['role' => 'sales', 'is_active' => true])->assertOk();
        $this->login($viewer, $company);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('role', 'sales');
    }

    public function test_ordered_approvals_require_correct_role_and_issue_only_on_final_step(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $accountant = $this->member($company, 'accountant');
        $sales = $this->member($company, 'sales');
        $this->login($owner, $company);
        $this->putJson('/api/v1/invoice-workflow', $this->config())->assertOk();
        $id = $this->draft($company, $sales);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->login($owner, $company);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertForbidden();
        $this->getJson('/api/v1/business')->assertOk()->assertJsonPath('invoices.0.allowed_actions', []);
        $this->login($accountant, $company);
        $this->getJson('/api/v1/business')->assertOk()->assertJsonPath('invoices.0.allowed_actions', ['approve', 'reject']);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'pending', 'number' => null, 'approval_step' => 1]);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertConflict();
        $this->login($owner, $company);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 1])->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'issued', 'number' => 'FAC-'.today()->year.'-00001']);
        $this->assertDatabaseCount('invoice_approvals', 2);
        $this->getJson('/api/v1/business')->assertOk()->assertJsonPath('invoices.0.approvals.0.actor', $accountant->name);
    }

    public function test_threshold_applies_to_ttc_and_below_threshold_uses_single_approval(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $sales = $this->member($company, 'sales');
        $this->login($owner, $company);
        $this->putJson('/api/v1/invoice-workflow', $this->config())->assertOk();
        $id = $this->draft($company, $sales, 999);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $invoice = DB::table('invoices')->find($id);
        $this->assertCount(1, json_decode($invoice->approval_snapshot, true)['steps']);
        $this->login($owner, $company);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertOk();
        $id = $this->draft($company, $sales, 1000);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertUnprocessable();
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'draft']);
    }

    public function test_self_approval_is_prevented_even_below_threshold(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $this->member($company, 'accountant');
        $this->login($owner, $company);
        $this->putJson('/api/v1/invoice-workflow', $this->config())->assertOk();
        $id = $this->draft($company, $owner, 500);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertForbidden();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'reject', 'round' => 1, 'step' => 0, 'comment' => 'Refus propre'])->assertForbidden();
    }

    public function test_config_changes_do_not_rewrite_pending_circuits_and_rejection_requires_a_reason(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $accountant = $this->member($company, 'accountant');
        $sales = $this->member($company, 'sales');
        $this->login($owner, $company);
        $this->putJson('/api/v1/invoice-workflow', $this->config())->assertOk();
        $id = $this->draft($company, $sales);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->login($owner, $company);
        $this->putJson('/api/v1/invoice-workflow', $this->config(['enabled' => false]))->assertOk();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertForbidden();
        $this->login($accountant, $company);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'reject', 'round' => 1, 'step' => 0])->assertUnprocessable();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'reject', 'round' => 1, 'step' => 0, 'comment' => 'Corriger les conditions'])->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'draft']);
        $this->login($sales, $company);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->login($owner, $company);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertConflict();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 2, 'step' => 0])->assertOk();
        $this->assertDatabaseHas('invoice_approvals', ['invoice_id' => $id, 'round' => 1, 'comment' => 'Corriger les conditions']);
        $this->assertDatabaseHas('invoice_approvals', ['invoice_id' => $id, 'round' => 2, 'action' => 'approve']);
    }

    public function test_distinct_approvers_and_stale_double_click_are_enforced(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $first = $this->member($company, 'accountant');
        $second = $this->member($company, 'accountant');
        $sales = $this->member($company, 'sales');
        $this->login($owner, $company);
        $config = $this->config(['steps' => [['role' => 'accountant', 'name' => 'Contrôle 1'], ['role' => 'accountant', 'name' => 'Contrôle 2']]]);
        $this->putJson('/api/v1/invoice-workflow', $config)->assertOk();
        $id = $this->draft($company, $sales);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->login($first, $company);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertOk();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 1])->assertForbidden();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 0])->assertConflict();
        $this->login($second, $company);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 1, 'step' => 1])->assertOk();
    }

    public function test_custom_validator_role_cannot_lose_approval_permission_while_used(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $role = $this->customRole($company, ['invoices.read', 'invoices.approve']);
        $this->member($company, $role);
        $this->login($owner, $company);
        $config = $this->config(['steps' => [['role' => $role, 'name' => 'Contrôle dédié']]]);
        $this->putJson('/api/v1/invoice-workflow', $config)->assertOk();
        $this->putJson('/api/v1/roles/'.substr($role, 7), ['name' => 'Lecteur', 'permissions' => ['invoices.read']])->assertUnprocessable();
        $id = $this->draft($company, $owner);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->putJson('/api/v1/invoice-workflow', [...$config, 'enabled' => false])->assertOk();
        $this->putJson('/api/v1/roles/'.substr($role, 7), ['name' => 'Lecteur', 'permissions' => ['invoices.read']])->assertUnprocessable();
    }

    public function test_role_changes_apply_to_existing_sessions_without_relogin(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $role = $this->customRole($company, ['contacts.read', 'contacts.write']);
        $user = $this->member($company, $role);
        $this->login($user, $company);
        $this->postJson('/api/v1/contacts', ['name' => 'Client', 'type' => 'client'])->assertCreated();
        $this->login($owner, $company);
        $this->putJson('/api/v1/roles/'.substr($role, 7), ['name' => 'Lecture contacts', 'permissions' => ['contacts.read']])->assertOk();
        $this->login($user, $company);
        $this->postJson('/api/v1/contacts', ['name' => 'Interdit', 'type' => 'client'])->assertForbidden();
    }

    public function test_only_drafts_can_be_edited_and_history_survives_corrections(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $id = $this->draft($company, $owner);
        $invoice = DB::table('invoices')->find($id);
        $payload = ['contact_id' => $invoice->contact_id, 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
            'tax_basis_points' => 0, 'notes' => 'Conditions corrigées', 'items' => [['description' => 'Service corrigé', 'quantity' => 2, 'unit_price' => 2000]]];
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->putJson("/api/v1/invoices/$id", $payload)->assertUnprocessable();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'reject', 'round' => 1, 'step' => 0, 'comment' => 'Corriger le service'])->assertOk();
        $this->putJson("/api/v1/invoices/$id", $payload)->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $id, 'total' => 4000, 'created_by' => $owner->id, 'approval_round' => 1, 'status' => 'draft']);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('invoice_approvals', ['invoice_id' => $id, 'action' => 'reject']);
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'submit'])->assertOk();
        $this->postJson("/api/v1/invoices/$id/transition", ['action' => 'approve', 'round' => 2, 'step' => 0])->assertOk();
        $this->putJson("/api/v1/invoices/$id", $payload)->assertUnprocessable();
        $other = $this->company();
        $otherOwner = $this->member($other, 'owner');
        $otherId = $this->draft($other, $otherOwner);
        $this->login($owner, $company);
        $this->putJson("/api/v1/invoices/$otherId", $payload)->assertNotFound();
    }
}
