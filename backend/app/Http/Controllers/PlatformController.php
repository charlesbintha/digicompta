<?php

namespace App\Http\Controllers;

use App\Support\Communications;
use App\Support\Entitlements;
use App\Support\Tenant;
use App\Support\WavePayments;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlatformController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        abort_unless($r->user()->is_super_admin, 403);

        return response()->json(['plans' => DB::table('plans')->orderBy('id')->get()->map(fn ($p) => $this->planData($p)), 'promotions' => DB::table('promotions')->orderByDesc('id')->get(), 'orders' => DB::table('subscription_orders')->join('companies', 'companies.id', '=', 'subscription_orders.company_id')->select('subscription_orders.*', 'companies.name as company_name')->orderByDesc('subscription_orders.id')->limit(200)->get(), 'companies' => DB::table('companies')->orderByDesc('id')->get(), 'audit' => DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->leftJoin('companies', 'companies.id', '=', 'audit_logs.company_id')->select('audit_logs.*', 'users.name as actor', 'companies.name as company_name')->orderByDesc('audit_logs.id')->limit(200)->get()]);
    }

    private function planData(object $p): object
    {
        $p->modules = json_decode($p->modules, true);

        return $p;
    }

    public function plan(Request $r, ?int $id = null): JsonResponse
    {
        abort_unless($r->user()->is_super_admin, 403);
        $d = $r->validate(['name' => ['required', 'string', 'max:80', Rule::unique('plans')->ignore($id)], 'monthly_price' => 'required|integer|min:0|max:1000000000', 'max_users' => 'nullable|integer|min:1|max:100000', 'monthly_invoices' => 'nullable|integer|min:1|max:1000000', 'modules' => 'present|array', 'modules.*' => ['distinct', Rule::in(['accounting', 'commerce', 'communications', 'support'])], 'sla_response_hours' => 'required|integer|min:1|max:720', 'sla_resolution_hours' => 'required|integer|min:1|max:2160|gte:sla_response_hours', 'active' => 'required|boolean']);
        DB::transaction(function () use ($r, $id, $d) {
            $d['modules'] = json_encode($d['modules']);
            $d['updated_at'] = now();
            if ($id) {
                $old = DB::table('plans')->where('id', $id)->lockForUpdate()->first();
                abort_unless($old, 404);
                DB::table('companies')->where('plan', $old->name)->update(['plan' => $d['name']]);
                DB::table('plans')->where('id', $id)->update($d);
            } else {
                $id = DB::table('plans')->insertGetId([...$d, 'created_at' => now()]);
            }$this->audit($r, 'Offre enregistrée', 'plans', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function promotion(Request $r, ?int $id = null): JsonResponse
    {
        abort_unless($r->user()->is_super_admin, 403);
        $r->merge(['code' => strtoupper(trim((string) $r->input('code')))]);
        $d = $r->validate(['code' => ['required', 'regex:/^[A-Z0-9_-]{3,40}$/', Rule::unique('promotions')->ignore($id)], 'type' => ['required', Rule::in(['percent', 'fixed'])], 'value' => 'required|integer|min:1|max:1000000000', 'plan_id' => 'nullable|integer|exists:plans,id', 'max_uses' => 'nullable|integer|min:1', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at', 'active' => 'required|boolean']);
        abort_if($d['type'] === 'percent' && $d['value'] > 100, 422, 'La réduction ne peut pas dépasser 100 %.');
        DB::transaction(function () use ($r, $id, $d) {
            if ($id) {
                abort_unless(DB::table('promotions')->where('id', $id)->lockForUpdate()->first(), 404);
                DB::table('promotions')->where('id', $id)->update([...$d, 'updated_at' => now()]);
            } else {
                $id = DB::table('promotions')->insertGetId([...$d, 'created_at' => now(), 'updated_at' => now()]);
            }$this->audit($r, 'Promotion enregistrée', 'promotions', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function subscription(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'subscription.read');
        $company = Tenant::company($r);
        $plan = Entitlements::plan($company);

        return response()->json(['wave_enabled' => WavePayments::enabled(), 'company' => $company, 'current_plan' => $plan ? $this->planData($plan) : null, 'active' => Entitlements::active($company), 'plans' => DB::table('plans')->where('active', true)->orderBy('monthly_price')->get()->map(fn ($p) => $this->planData($p)), 'orders' => Tenant::table($r, 'subscription_orders')->orderByDesc('id')->get(), 'usage' => ['users' => Tenant::table($r, 'memberships')->where('is_active', true)->count(), 'invoices' => Tenant::table($r, 'invoices')->where('created_at', '>=', now()->startOfMonth())->count()]]);
    }

    public function order(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'subscription.write');
        $d = $r->validate(['plan_id' => 'required|integer', 'code' => 'nullable|string|max:40']);
        $id = DB::transaction(function () use ($r, $d) {
            $company = DB::table('companies')->where('id', Tenant::company($r)->id)->lockForUpdate()->first();
            abort_if(DB::table('subscription_orders')->where('company_id', $company->id)->where('status', 'pending')->exists(), 422, 'Une demande est déjà en attente.');
            $plan = DB::table('plans')->where('id', $d['plan_id'])->where('active', true)->lockForUpdate()->first();
            abort_unless($plan, 422, 'Offre indisponible.');
            $promo = null;
            $discount = 0;
            if (! empty($d['code'])) {
                $promo = DB::table('promotions')->where('code', strtoupper(trim($d['code'])))->lockForUpdate()->first();
                abort_unless($promo && $promo->active && (! $promo->plan_id || $promo->plan_id === $plan->id) && (! $promo->starts_at || now()->gte($promo->starts_at)) && (! $promo->ends_at || now()->lte($promo->ends_at)) && (! $promo->max_uses || $promo->uses < $promo->max_uses), 422, 'Code promotionnel invalide ou épuisé.');
                abort_if(DB::table('promotion_redemptions')->where('promotion_id', $promo->id)->where('company_id', $company->id)->exists(), 422, 'Cette promotion a déjà été utilisée par votre entreprise.');
                $discount = min($plan->monthly_price, $promo->type === 'percent' ? intdiv($plan->monthly_price * $promo->value + 50, 100) : $promo->value);
            }
            $id = DB::table('subscription_orders')->insertGetId(['company_id' => $company->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'promotion_id' => $promo?->id, 'subtotal' => $plan->monthly_price, 'discount' => $discount, 'total' => $plan->monthly_price - $discount, 'created_at' => now(), 'updated_at' => now()]);
            if ($promo) {
                DB::table('promotions')->where('id', $promo->id)->increment('uses');
                DB::table('promotion_redemptions')->insert(['promotion_id' => $promo->id, 'company_id' => $company->id, 'order_id' => $id]);
            }Tenant::audit($r, 'Demande d’abonnement', 'subscription_orders', $id);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function decideOrder(Request $r, int $id): JsonResponse
    {
        abort_unless($r->user()->is_super_admin, 403);
        $d = $r->validate(['action' => ['required', Rule::in(['confirm', 'cancel'])], 'payment_reference' => 'required_if:action,confirm|nullable|string|min:3|max:200']);
        DB::transaction(function () use ($r, $d, $id) {
            $order = DB::table('subscription_orders')->find($id);
            abort_unless($order, 404);
            $company = DB::table('companies')->where('id', $order->company_id)->lockForUpdate()->first();
            $order = DB::table('subscription_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order->status === 'pending', 409, 'Cette demande a déjà été traitée.');
            abort_if($order->wave_reference && $order->wave_state !== 'expired', 409, 'Vérifiez le paiement Wave avant de traiter cette demande manuellement.');
            if ($d['action'] === 'confirm') {
                $plan = DB::table('plans')->find($order->plan_id);
                $base = $company->plan === $plan->name && $company->subscription_ends_at && now()->lt($company->subscription_ends_at) ? Carbon::parse($company->subscription_ends_at) : now();
                DB::table('companies')->where('id', $company->id)->update(['plan' => $plan->name, 'subscription_status' => 'active', 'subscription_ends_at' => $base->copy()->addDays(30), 'updated_at' => now()]);
                DB::table('subscription_orders')->where('id', $id)->update(['status' => 'paid', 'payment_reference' => $d['payment_reference'], 'paid_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('subscription_orders')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                if ($order->promotion_id) {
                    DB::table('promotions')->where('id', $order->promotion_id)->lockForUpdate()->first();
                    DB::table('promotion_redemptions')->where('order_id', $id)->delete();
                    DB::table('promotions')->where('id', $order->promotion_id)->where('uses', '>', 0)->decrement('uses');
                }
            }
            $this->audit($r, $d['action'] === 'confirm' ? 'Règlement abonnement confirmé' : 'Demande annulée', 'subscription_orders', $id, $company->id);
            Communications::notifyPermission($company->id, 'subscription.read', 'Abonnement mis à jour', 'Votre demande d’abonnement a été traitée.', 'subscription');
        });

        return response()->json(['ok' => true]);
    }

    private function audit(Request $r, string $action, string $type, int $id, ?int $company = null): void
    {
        DB::table('audit_logs')->insert(['company_id' => $company ?? $r->session()->get('company_id'), 'user_id' => $r->user()->id, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'created_at' => now()]);
    }
}
