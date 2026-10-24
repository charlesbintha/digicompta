<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Entitlements
{
    public static function plan(object $company): ?object
    {
        return DB::table('plans')->where('name', $company->plan)->first();
    }

    public static function active(object $company): bool
    {
        return $company->subscription_status !== 'suspended' && (! ($company->subscription_status === 'trial' && $company->trial_ends_at && now()->greaterThan($company->trial_ends_at))) && (! ($company->subscription_status === 'active' && $company->subscription_ends_at && now()->greaterThan($company->subscription_ends_at)));
    }

    public static function write(Request $r, ?string $quota = null): void
    {
        $company = DB::table('companies')->where('id', Tenant::company($r)->id)->lockForUpdate()->first();
        abort_unless(self::active($company), 403, 'Votre abonnement a expiré ou est suspendu. Consultez la rubrique Abonnement.');
        $plan = self::plan($company);
        if ($quota === 'users' && $plan?->max_users !== null) {
            abort_if(DB::table('memberships')->where('company_id', $company->id)->where('is_active', true)->count() >= $plan->max_users, 422, 'Le quota de collaborateurs actifs est atteint.');
        }
        if ($quota === 'invoices' && $plan?->monthly_invoices !== null) {
            abort_if(DB::table('invoices')->where('company_id', $company->id)->where('created_at', '>=', now()->startOfMonth())->count() >= $plan->monthly_invoices, 422, 'Le quota mensuel de factures est atteint.');
        }
    }

    public static function filter(object $company, array $permissions): array
    {
        $plan = self::plan($company);
        $modules = $plan ? json_decode($plan->modules, true) : ['accounting', 'commerce', 'communications', 'support'];

        return array_values(array_filter($permissions, function (string $p) use ($modules): bool {
            $prefix = explode('.', $p)[0];
            $module = match ($prefix) {
                'quotes','purchases','credits','stock','refunds' => 'commerce','accounting', 'banking' => 'accounting','communications' => 'communications',default => null
            };

            return $module === null || in_array($module, $modules, true);
        }));
    }
}
