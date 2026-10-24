<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Tenant
{
    public static function catalog(): array
    {
        return [
            'banking.read' => 'Consulter les rapprochements bancaires', 'banking.write' => 'Rapprocher les relevés bancaires', 'refunds.read' => 'Consulter les remboursements', 'refunds.write' => 'Enregistrer les remboursements clients',
            'accounting.read' => 'Consulter la comptabilité', 'accounting.write' => 'Saisir des écritures', 'accounting.close' => 'Configurer et clôturer la comptabilité',
            'quotes.read' => 'Consulter les devis', 'quotes.write' => 'Gérer les devis',
            'purchases.read' => 'Consulter les achats', 'purchases.write' => 'Saisir les achats', 'purchases.approve' => 'Valider les achats', 'purchases.pay' => 'Régler les fournisseurs',
            'credits.write' => 'Émettre des avoirs', 'stock.read' => 'Consulter les stocks', 'stock.write' => 'Ajuster les stocks',
            'expenses.approve' => 'Valider les dépenses', 'communications.read' => 'Consulter les envois', 'communications.write' => 'Envoyer des emails', 'communications.manage' => 'Configurer le SMTP',
            'subscription.read' => 'Consulter les offres', 'subscription.write' => 'Demander un abonnement', 'support.read' => 'Consulter le support', 'support.write' => 'Contacter le support',
            'dashboard.read' => 'Consulter le tableau de bord',
            'contacts.read' => 'Consulter les clients et fournisseurs', 'contacts.write' => 'Créer des clients et fournisseurs',
            'products.read' => 'Consulter le catalogue', 'products.write' => 'Créer des produits et services',
            'invoices.read' => 'Consulter les factures', 'invoices.write' => 'Créer et soumettre des factures', 'invoices.approve' => 'Valider ou rejeter des factures',
            'payments.read' => 'Consulter les règlements', 'payments.write' => 'Enregistrer des règlements',
            'expenses.read' => 'Consulter les dépenses', 'expenses.write' => 'Enregistrer des dépenses',
            'team.read' => 'Consulter les collaborateurs', 'team.write' => 'Gérer les collaborateurs',
            'audit.read' => 'Consulter le journal d’activité',
            'settings.read' => 'Consulter les paramètres', 'settings.write' => 'Modifier les informations de l’entreprise',
            'roles.manage' => 'Gérer les rôles et permissions', 'workflows.manage' => 'Configurer les circuits de validation',
        ];
    }

    public static function dependencies(): array
    {
        return [
            'banking.read' => ['accounting.read'], 'banking.write' => ['banking.read', 'accounting.read'], 'refunds.read' => ['invoices.read'], 'refunds.write' => ['refunds.read', 'invoices.read'],
            'accounting.write' => ['accounting.read'], 'accounting.close' => ['accounting.read'], 'quotes.write' => ['quotes.read', 'contacts.read'],
            'purchases.read' => ['contacts.read'], 'purchases.write' => ['purchases.read', 'contacts.read'], 'purchases.approve' => ['purchases.read', 'contacts.read'], 'purchases.pay' => ['purchases.read', 'contacts.read'],
            'credits.write' => ['invoices.read'], 'stock.write' => ['stock.read', 'products.read'], 'expenses.approve' => ['expenses.read'],
            'communications.write' => ['communications.read', 'invoices.read'], 'communications.manage' => ['communications.read'], 'subscription.write' => ['subscription.read'], 'support.write' => ['support.read'],
            'dashboard.read' => ['invoices.read', 'payments.read', 'expenses.read'],
            'contacts.write' => ['contacts.read'], 'products.write' => ['products.read'],
            'invoices.write' => ['invoices.read', 'contacts.read', 'products.read'],
            'invoices.approve' => ['invoices.read'], 'payments.write' => ['payments.read', 'invoices.read'],
            'expenses.write' => ['expenses.read'], 'team.write' => ['team.read'],
            'settings.write' => ['settings.read'], 'roles.manage' => ['team.read'], 'workflows.manage' => ['invoices.read'],
        ];
    }

    public static function company(Request $request): object
    {
        $membership = DB::table('memberships')->where('user_id', $request->user()->id)
            ->where('company_id', $request->session()->get('company_id'))->where('is_active', true)->first();
        abort_unless($membership, 403, 'Votre accès à cette entreprise est désactivé ou indisponible.');
        $company = DB::table('companies')->find($membership->company_id);
        abort_if($company->subscription_status === 'suspended' && ! $request->is('api/v1/login', 'api/v1/me', 'api/v1/subscription*', 'api/v1/support*', 'api/v1/notifications*', 'api/v1/push*'), 403, 'Cet espace est suspendu. Contactez le support.');

        return $company;
    }

    public static function role(Request $request): string
    {
        return DB::table('memberships')->where('user_id', $request->user()->id)->where('company_id', self::company($request)->id)->value('role');
    }

    public static function permissions(string $role, ?int $companyId = null): array
    {
        $reads = ['quotes.read', 'purchases.read', 'stock.read', 'support.read', 'support.write', 'subscription.read', 'dashboard.read', 'contacts.read', 'products.read', 'invoices.read', 'payments.read', 'expenses.read', 'team.read', 'audit.read', 'settings.read'];
        if (str_starts_with($role, 'custom:')) {
            $json = DB::table('company_roles')->where('company_id', $companyId)->where('id', substr($role, 7))->value('permissions');

            return $json ? json_decode($json, true) : [];
        }

        return match ($role) {
            'owner' => array_keys(self::catalog()),
            'accountant' => [...$reads, 'banking.read', 'banking.write', 'refunds.read', 'refunds.write', 'accounting.read', 'accounting.write', 'accounting.close', 'quotes.write', 'purchases.write', 'purchases.approve', 'purchases.pay', 'credits.write', 'stock.write', 'expenses.approve', 'communications.read', 'communications.write', 'contacts.write', 'products.write', 'invoices.write', 'invoices.approve', 'payments.write', 'expenses.write'],
            'sales' => [...$reads, 'quotes.write', 'communications.read', 'communications.write', 'contacts.write', 'products.write', 'invoices.write'],
            'viewer' => $reads,
            default => [],
        };
    }

    public static function effectivePermissions(Request $request): array
    {
        return Entitlements::filter(self::company($request), self::permissions(self::role($request), self::company($request)->id));
    }

    public static function allows(Request $request, string $permission): bool
    {
        return in_array($permission, self::effectivePermissions($request), true);
    }

    public static function authorize(Request $request, string $permission): void
    {
        abort_unless(self::allows($request, $permission), 403, 'Vous ne disposez pas de cette permission.');
        if (! str_ends_with($permission, '.read') && ! str_starts_with($permission, 'subscription.') && ! str_starts_with($permission, 'support.')) {
            abort_unless(Entitlements::active(self::company($request)), 403, 'Abonnement expiré ou suspendu. Consultez la rubrique Abonnement.');
        }
    }

    public static function roleOptions(int $companyId): array
    {
        $roles = [];
        foreach (['owner' => 'Propriétaire', 'accountant' => 'Comptable', 'sales' => 'Commercial', 'viewer' => 'Lecture seule'] as $key => $name) {
            $roles[] = ['key' => $key, 'name' => $name, 'builtin' => true, 'permissions' => self::permissions($key)];
        }
        foreach (DB::table('company_roles')->where('company_id', $companyId)->orderBy('name')->get() as $role) {
            $roles[] = ['key' => 'custom:'.$role->id, 'id' => $role->id, 'name' => $role->name, 'builtin' => false, 'permissions' => json_decode($role->permissions, true)];
        }

        return $roles;
    }

    public static function table(Request $request, string $table): Builder
    {
        return DB::table($table)->where($table.'.company_id', self::company($request)->id);
    }

    public static function audit(Request $request, string $action, string $type, int $id): void
    {
        DB::table('audit_logs')->insert(['company_id' => self::company($request)->id, 'user_id' => $request->user()->id,
            'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'created_at' => now()]);
    }
}
