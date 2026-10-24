<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Entitlements;
use App\Support\InvoiceWorkflow;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(Tenant::allows($request, 'team.read') || Tenant::allows($request, 'workflows.manage'), 403);

        return response()->json(['roles' => array_map(fn (array $role) => [...$role, 'permissions' => Entitlements::filter(Tenant::company($request), $role['permissions'])], Tenant::roleOptions(Tenant::company($request)->id)), 'catalog' => Tenant::catalog(),
            'dependencies' => Tenant::dependencies(),
            'workflow' => Tenant::allows($request, 'workflows.manage') ? InvoiceWorkflow::configuration(Tenant::company($request)->id) : null]);
    }

    private function lockCompany(Request $request): void
    {
        DB::table('companies')->where('id', Tenant::company($request)->id)->lockForUpdate()->first();
    }

    private function assertGrantable(Request $request, array $permissions): void
    {
        abort_if(array_diff($permissions, Tenant::effectivePermissions($request)), 403, 'Vous ne pouvez pas déléguer ou modifier des droits supérieurs aux vôtres.');
    }

    private function roleForAssignment(Request $request, string $key): array
    {
        $role = collect(Tenant::roleOptions(Tenant::company($request)->id))->firstWhere('key', $key);
        abort_unless($role && $key !== 'owner', 422, 'Choisissez un rôle de cette entreprise, autre que propriétaire.');
        $this->assertGrantable($request, Entitlements::filter(Tenant::company($request), $role['permissions']));

        return $role;
    }

    public function saveRole(Request $request, ?int $id = null): JsonResponse
    {
        Tenant::authorize($request, 'roles.manage');
        $company = Tenant::company($request)->id;
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('company_roles', 'name')->where('company_id', $company)->ignore($id)],
            'permissions' => 'present|array', 'permissions.*' => ['string', 'distinct', Rule::in(array_keys(Tenant::catalog()))]]);
        $savedId = DB::transaction(function () use ($request, $id, $data) {
            $this->lockCompany($request);
            Tenant::authorize($request, 'roles.manage');
            $this->assertGrantable($request, $data['permissions']);
            foreach ($data['permissions'] as $permission) {
                abort_if(array_diff(Tenant::dependencies()[$permission] ?? [], $data['permissions']), 422, 'Ajoutez les droits de consultation nécessaires à cette permission.');
            }
            if ($id !== null) {
                $role = Tenant::table($request, 'company_roles')->find($id);
                abort_unless($role, 404);
                $this->assertGrantable($request, json_decode($role->permissions, true));
                abort_if(Tenant::role($request) === 'custom:'.$id, 422, 'Vous ne pouvez pas modifier votre propre rôle. Demandez au propriétaire.');
                if (! in_array('invoices.approve', $data['permissions'], true)) {
                    $key = 'custom:'.$id;
                    $config = InvoiceWorkflow::configuration(Tenant::company($request)->id);
                    $used = $config['enabled'] && collect($config['steps'])->contains('role', $key);
                    foreach (Tenant::table($request, 'invoices')->where('status', 'pending')->get() as $invoice) {
                        $used = $used || collect(InvoiceWorkflow::snapshot($invoice)['steps'])->contains('role', $key);
                    }
                    abort_if($used, 422, 'Ce rôle intervient dans un circuit de validation. Conservez son droit de validation.');
                }
                Tenant::table($request, 'company_roles')->where('id', $id)->update(['name' => $data['name'], 'permissions' => json_encode($data['permissions']), 'updated_at' => now()]);
            } else {
                $id = DB::table('company_roles')->insertGetId(['company_id' => Tenant::company($request)->id, 'name' => $data['name'], 'permissions' => json_encode($data['permissions']), 'created_at' => now(), 'updated_at' => now()]);
            }
            Tenant::audit($request, 'Rôle enregistré : '.$data['name'], 'company_roles', $id);

            return $id;
        });

        return response()->json(['id' => $savedId], $id === null ? 201 : 200);
    }

    public function member(Request $request): JsonResponse
    {
        Tenant::authorize($request, 'team.write');
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:200|unique:users,email',
            'password' => ['required', Password::min(12)], 'role' => 'required|string|max:100']);
        DB::transaction(function () use ($request, $data) {
            $this->lockCompany($request);
            Tenant::authorize($request, 'team.write');
            $this->roleForAssignment($request, $data['role']);
            Entitlements::write($request, 'users');
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $id = DB::table('memberships')->insertGetId(['company_id' => Tenant::company($request)->id, 'user_id' => $user->id, 'role' => $data['role'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            Tenant::audit($request, 'Membre ajouté', 'memberships', $id);
        });

        return response()->json(['ok' => true], 201);
    }

    public function updateMember(Request $request, int $id): JsonResponse
    {
        Tenant::authorize($request, 'team.write');
        $data = $request->validate(['role' => 'required|string|max:100', 'is_active' => 'required|boolean']);
        DB::transaction(function () use ($request, $data, $id) {
            $this->lockCompany($request);
            Tenant::authorize($request, 'team.write');
            $member = Tenant::table($request, 'memberships')->where('id', $id)->lockForUpdate()->first();
            abort_unless($member, 404);
            abort_if($member->role === 'owner' || $member->user_id === $request->user()->id, 422, 'Le propriétaire et votre propre accès ne peuvent pas être modifiés ici.');
            if (! $member->is_active && $data['is_active']) {
                Entitlements::write($request, 'users');
            }
            $this->assertGrantable($request, Entitlements::filter(Tenant::company($request), Tenant::permissions($member->role, Tenant::company($request)->id)));
            $this->roleForAssignment($request, $data['role']);
            Tenant::table($request, 'memberships')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            Tenant::audit($request, $data['is_active'] ? 'Accès collaborateur mis à jour' : 'Accès collaborateur désactivé', 'memberships', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function workflow(Request $request): JsonResponse
    {
        Tenant::authorize($request, 'workflows.manage');
        $data = $request->validate(['enabled' => 'required|boolean', 'threshold' => 'required|integer|min:0|max:2000000000000',
            'prevent_self_approval' => 'required|boolean', 'distinct_approvers' => 'required|boolean', 'steps' => 'required|array|min:1|max:5',
            'steps.*' => 'array:role,name', 'steps.*.role' => 'required|string|max:100', 'steps.*.name' => 'required|string|max:90']);
        DB::transaction(function () use ($request, $data) {
            $this->lockCompany($request);
            Tenant::authorize($request, 'workflows.manage');
            $roles = collect(Tenant::roleOptions(Tenant::company($request)->id))->keyBy('key');
            foreach ($data['steps'] as $step) {
                $role = $roles->get($step['role']);
                abort_unless($role && in_array('invoices.approve', $role['permissions'], true), 422, 'Chaque étape doit viser un rôle de votre entreprise disposant du droit de validation.');
            }
            $company = Tenant::company($request)->id;
            $row = DB::table('invoice_workflows')->where('company_id', $company)->first();
            $values = [...$data, 'steps' => json_encode($data['steps']), 'updated_at' => now()];
            if ($row) {
                DB::table('invoice_workflows')->where('id', $row->id)->update($values);
            } else {
                DB::table('invoice_workflows')->insert([...$values, 'company_id' => $company, 'created_at' => now()]);
            }
            Tenant::audit($request, 'Circuit de validation modifié', 'companies', $company);
        });

        return response()->json(['ok' => true]);
    }
}
