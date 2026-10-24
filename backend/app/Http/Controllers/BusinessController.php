<?php

namespace App\Http\Controllers;

use App\Support\Entitlements;
use App\Support\InvoiceWorkflow;
use App\Support\Ledger;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $permissions = Tenant::effectivePermissions($request);
        $can = fn (string $permission) => in_array($permission, $permissions, true);
        $invoices = $can('invoices.read') ? Tenant::table($request, 'invoices')->orderByDesc('id')->get() : collect();
        $payments = ($can('payments.read') || $can('invoices.read')) ? Tenant::table($request, 'payments')->orderByDesc('paid_at')->get() : collect();
        $items = DB::table('invoice_items')->whereIn('invoice_id', $invoices->pluck('id'))->get()->groupBy('invoice_id');
        $decisions = Tenant::table($request, 'invoice_approvals')->whereIn('invoice_id', $invoices->pluck('id'))
            ->join('users', 'users.id', '=', 'invoice_approvals.user_id')->select('invoice_approvals.*', 'users.name as actor')->orderBy('invoice_approvals.id')->get()->groupBy('invoice_id');
        foreach ($invoices as $invoice) {
            $invoice->customer_snapshot = json_decode($invoice->customer_snapshot);
            $invoice->seller_snapshot = json_decode($invoice->seller_snapshot);
            $invoice->paid = (int) $payments->where('invoice_id', $invoice->id)->sum('amount');
            $invoice->refunded = (int) Tenant::table($request, 'refunds')->where('invoice_id', $invoice->id)->sum('amount');
            $invoice->refund_due = max(0, $invoice->paid - $invoice->refunded - ($invoice->total - $invoice->credited));
            $invoice->items = $items->get($invoice->id, collect());
            $invoice->approvals = $decisions->get($invoice->id, collect());
            $invoice->allowed_actions = [];
            if ($invoice->status === 'draft' && ! $invoice->archived && $can('invoices.write')) {
                $invoice->allowed_actions = ['edit', 'submit'];
            }
            if (InvoiceWorkflow::canDecide($request, $invoice, $invoice->approvals->all())) {
                $invoice->allowed_actions = [...$invoice->allowed_actions, 'approve', 'reject'];
            }
            $invoice->approval_snapshot = $invoice->approval_snapshot ? json_decode($invoice->approval_snapshot) : null;
        }

        return response()->json([
            'contacts' => $can('contacts.read') ? Tenant::table($request, 'contacts')->orderBy('name')->get() : [],
            'products' => $can('products.read') ? Tenant::table($request, 'products')->orderBy('name')->get() : [],
            'invoices' => $invoices, 'payments' => $can('payments.read') ? $payments : [],
            'refunds' => $can('payments.read') ? Tenant::table($request, 'refunds')->get() : [],
            'purchase_payments' => $can('purchases.read') ? Tenant::table($request, 'purchase_payments')->get() : [],
            'expenses' => $can('expenses.read') ? Tenant::table($request, 'expenses')->orderByDesc('paid_at')->get() : [],
            'audit' => $can('audit.read') ? Tenant::table($request, 'audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->select('audit_logs.*', 'users.name as actor')->orderByDesc('audit_logs.id')->limit(50)->get() : [],
            'team' => $can('team.read') ? Tenant::table($request, 'memberships')->join('users', 'users.id', '=', 'memberships.user_id')->select('memberships.id', 'memberships.user_id', 'memberships.role', 'memberships.is_active', 'users.name', 'users.email')->get() : [],
        ]);
    }

    public function contact(Request $request): JsonResponse
    {
        Tenant::authorize($request, 'contacts.write');
        $data = $request->validate(['type' => ['required', Rule::in(['client', 'supplier'])], 'name' => 'required|string|max:150',
            'email' => 'nullable|email|max:200', 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:255']);

        return $this->insert($request, 'contacts', $data);
    }

    public function product(Request $request): JsonResponse
    {
        Tenant::authorize($request, 'products.write');
        $data = $request->validate(['name' => 'required|string|max:150', 'sku' => 'nullable|string|max:80',
            'type' => ['required', Rule::in(['product', 'service'])], 'price' => 'required|integer|min:0|max:1000000000']);

        return $this->insert($request, 'products', $data);
    }

    public function expense(Request $request): JsonResponse
    {
        Tenant::authorize($request, 'expenses.write');
        $data = $request->validate(['description' => 'required|string|max:200', 'category' => 'required|string|max:100',
            'amount' => 'required|integer|min:1|max:1000000000', 'paid_at' => 'required|date_format:Y-m-d|before_or_equal:today']);

        return $this->insert($request, 'expenses', [...$data, 'status' => 'pending', 'created_by' => $request->user()->id]);
    }

    private function insert(Request $request, string $table, array $data): JsonResponse
    {
        $id = DB::transaction(function () use ($request, $table, $data) {
            Entitlements::write($request);
            if ($table === 'expenses') {
                $company = Tenant::company($request);
                abort_if($company->closed_through && $data['paid_at'] <= $company->closed_through, 422, 'Cette période comptable est clôturée.');
                $data['account_code'] = Ledger::mapping($company->id)['expense'];
            }
            $id = DB::table($table)->insertGetId([...$data, 'company_id' => Tenant::company($request)->id, 'created_at' => now(), 'updated_at' => now()]);
            Tenant::audit($request, 'Création', $table, $id);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function invoice(Request $request, ?int $id = null): JsonResponse
    {
        Tenant::authorize($request, 'invoices.write');
        $data = $request->validate(['contact_id' => 'required|integer', 'issue_date' => 'required|date_format:Y-m-d',
            'due_date' => 'required|date_format:Y-m-d|after_or_equal:issue_date', 'tax_basis_points' => 'required|integer|min:0|max:10000',
            'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1|max:100',
            'items.*' => 'array:description,quantity,unit_price,product_id', 'items.*.product_id' => 'nullable|integer', 'items.*.description' => 'required|string|max:200', 'items.*.quantity' => 'required|integer|min:1|max:10000',
            'items.*.unit_price' => 'required|integer|min:0|max:1000000000']);
        $client = Tenant::table($request, 'contacts')->where('type', 'client')->where('archived', false)->find($data['contact_id']);
        abort_unless($client, 422, 'Sélectionnez un client de votre entreprise.');
        $editing = $id !== null;
        $id = DB::transaction(function () use ($request, $data, $client, $id) {
            Entitlements::write($request, $id === null ? 'invoices' : null);
            if ($id !== null) {
                $existing = Tenant::table($request, 'invoices')->where('id', $id)->lockForUpdate()->first();
                abort_unless($existing, 404);
                abort_unless($existing->status === 'draft' && ! $existing->archived, 422, 'Seuls les brouillons peuvent être modifiés.');
            }
            $subtotal = 0;
            $items = [];
            foreach ($data['items'] as $item) {
                if (! empty($item['product_id'])) {
                    abort_unless(Tenant::table($request, 'products')->where('archived', false)->find($item['product_id']), 422, 'Produit de votre entreprise introuvable ou archivé.');
                }
                $total = (int) $item['quantity'] * (int) $item['unit_price'];
                $subtotal += $total;
                $items[] = [...$item, 'total' => $total];
            }
            abort_if($subtotal === 0, 422, 'Le total de la facture doit être supérieur à zéro.');
            abort_if($subtotal > 1000000000000, 422, 'Le montant de cette facture dépasse la limite autorisée.');
            $tax = intdiv($subtotal * (int) $data['tax_basis_points'] + 5000, 10000);
            $company = Tenant::company($request);
            abort_if($company->closed_through && $data['issue_date'] <= $company->closed_through, 422, 'Cette période comptable est clôturée.');
            $values = ['company_id' => $company->id, 'contact_id' => $client->id, 'status' => 'draft',
                'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'], 'tax_basis_points' => $data['tax_basis_points'],
                'customer_snapshot' => json_encode(['name' => $client->name, 'email' => $client->email, 'address' => $client->address]),
                'seller_snapshot' => json_encode(['name' => $company->name, 'address' => $company->address, 'ninea' => $company->ninea, 'rccm' => $company->rccm]),
                'subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $subtotal + $tax, 'notes' => $data['notes'] ?? null,
                'updated_at' => now()];
            $editing = $id !== null;
            if ($editing) {
                Tenant::table($request, 'invoices')->where('id', $id)->update($values);
                DB::table('invoice_items')->where('invoice_id', $id)->delete();
            } else {
                $id = DB::table('invoices')->insertGetId([...$values, 'created_by' => $request->user()->id, 'created_at' => now()]);
            }
            foreach ($items as $item) {
                DB::table('invoice_items')->insert([...$item, 'invoice_id' => $id]);
            }
            Tenant::audit($request, $editing ? 'Brouillon modifié' : 'Brouillon créé', 'invoices', $id);

            return $id;
        });

        return response()->json(['id' => $id], $editing ? 200 : 201);
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['submit', 'approve', 'reject'])],
            'round' => 'required_unless:action,submit|integer|min:0', 'step' => 'required_unless:action,submit|integer|min:0',
            'comment' => 'required_if:action,reject|nullable|string|min:3|max:1000']);
        Tenant::authorize($request, $data['action'] === 'submit' ? 'invoices.write' : 'invoices.approve');
        InvoiceWorkflow::transition($request, $id, $data);

        return response()->json(['ok' => true]);
    }

    public function payment(Request $request, int $id): JsonResponse
    {
        Tenant::authorize($request, 'payments.write');
        $data = $request->validate(['amount' => 'required|integer|min:1|max:2000000000000', 'paid_at' => 'required|date_format:Y-m-d|before_or_equal:today',
            'method' => ['required', Rule::in(['Virement', 'Espèces', 'Wave', 'Orange Money', 'Chèque'])], 'request_id' => 'required|uuid']);
        DB::transaction(function () use ($request, $id, $data) {
            Entitlements::write($request);
            $invoice = Tenant::table($request, 'invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            $existing = DB::table('payments')->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->company_id === $invoice->company_id && $existing->invoice_id === $id && (int) $existing->amount === (int) $data['amount'] && $existing->method === $data['method'] && $existing->paid_at === $data['paid_at'], 409, 'Référence de paiement déjà utilisée.');

                return;
            }
            abort_unless($invoice->status === 'issued', 422, 'Seule une facture validée et non soldée peut recevoir un règlement.');
            abort_if($data['paid_at'] < $invoice->issue_date, 422, 'Le règlement ne peut pas précéder la facture.');
            $paid = (int) DB::table('payments')->where('invoice_id', $id)->sum('amount');
            abort_if($data['amount'] > $invoice->total - $invoice->credited - $paid + (int) Tenant::table($request, 'refunds')->where('invoice_id', $id)->sum('amount'), 422, 'Le règlement dépasse le solde restant.');
            $paymentId = DB::table('payments')->insertGetId([...$data, 'invoice_id' => $id, 'company_id' => $invoice->company_id, 'created_at' => now(), 'updated_at' => now()]);
            Ledger::invoice($invoice, $request->user()->id);
            Ledger::payment($invoice->company_id, $request->user()->id, $paymentId, (int) $data['amount'], $data['paid_at'], $data['method']);
            if ($paid + (int) $data['amount'] + $invoice->credited - (int) Tenant::table($request, 'refunds')->where('invoice_id', $id)->sum('amount') === $invoice->total) {
                Tenant::table($request, 'invoices')->where('id', $id)->update(['status' => 'paid', 'updated_at' => now()]);
            }
            Tenant::audit($request, 'Règlement enregistré', 'invoices', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        Tenant::authorize($request, 'settings.write');
        $data = $request->validate(['name' => 'required|string|max:150', 'address' => 'nullable|string|max:255', 'ninea' => 'nullable|string|max:50', 'rccm' => 'nullable|string|max:80']);
        DB::transaction(function () use ($request, $data) {
            $id = Tenant::company($request)->id;
            DB::table('companies')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            Tenant::audit($request, 'Paramètres modifiés', 'companies', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function admin(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_super_admin, 403);

        return response()->json(DB::table('companies')->orderByDesc('id')->get());
    }

    public function subscription(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->is_super_admin, 403);
        $data = $request->validate(['plan' => ['required', Rule::exists('plans', 'name')],
            'subscription_status' => ['required', Rule::in(['trial', 'active', 'suspended'])]]);
        abort_unless(DB::table('companies')->where('id', $id)->exists(), 404);
        DB::transaction(function () use ($request, $id, $data) {
            DB::table('companies')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['company_id' => $id, 'user_id' => $request->user()->id, 'action' => 'Abonnement modifié par la plateforme', 'entity_type' => 'companies', 'entity_id' => $id, 'created_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }
}
