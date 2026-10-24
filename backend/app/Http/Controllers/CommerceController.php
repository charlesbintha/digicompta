<?php

namespace App\Http\Controllers;

use App\Support\Entitlements;
use App\Support\Inventory;
use App\Support\Ledger;
use App\Support\Tenant;
use Brick\Math\BigInteger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommerceController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $company = Tenant::company($r);
        $can = fn ($p) => Tenant::allows($r, $p);
        $quotes = $can('quotes.read') ? Tenant::table($r, 'quotes')->orderByDesc('id')->get() : collect();
        foreach ($quotes as $q) {
            $q->items = json_decode($q->items);
        }
        $purchases = $can('purchases.read') ? Tenant::table($r, 'purchases')->orderByDesc('id')->get() : collect();
        $payments = Tenant::table($r, 'purchase_payments')->whereIn('purchase_id', $purchases->pluck('id'))->get();
        foreach ($purchases as $p) {
            $p->paid = (int) $payments->where('purchase_id', $p->id)->sum('amount');
        }
        $movements = $can('stock.read') ? Tenant::table($r, 'stock_movements')->orderByDesc('id')->get() : collect();

        return response()->json(['quotes' => $quotes, 'purchases' => $purchases, 'purchase_payments' => $payments, 'credits' => $can('invoices.read') ? Tenant::table($r, 'credit_notes')->orderByDesc('id')->get() : [], 'movements' => $movements]);
    }

    public function updateRecord(Request $r, string $kind, int $id): JsonResponse
    {
        abort_unless(in_array($kind, ['contacts', 'products'], true), 404);
        Tenant::authorize($r, $kind.'.write');
        $rules = $kind === 'contacts' ? ['name' => 'required|string|max:150', 'email' => 'nullable|email|max:200', 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:255'] : ['name' => 'required|string|max:150', 'sku' => 'nullable|string|max:80', 'type' => ['required', Rule::in(['product', 'service'])], 'price' => 'required|integer|min:0|max:1000000000'];
        $d = $r->validate([...$rules, 'archived' => 'required|boolean']);
        DB::transaction(function () use ($r, $kind, $id, $d) {
            Entitlements::write($r);
            $row = Tenant::table($r, $kind)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($kind === 'products' && $d['type'] === 'service') {
                abort_if(Tenant::table($r, 'stock_movements')->where('product_id', $id)->sum('quantity') != 0, 422, 'Le stock doit être nul avant de convertir un produit en service.');
            }Tenant::table($r, $kind)->where('id', $id)->update([...$d, 'updated_at' => now()]);
            Tenant::audit($r, $d['archived'] ? 'Fiche archivée' : 'Fiche modifiée', $kind, $id);
        });

        return response()->json(['ok' => true]);
    }

    public function quote(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'quotes.write');
        $d = $r->validate(['contact_id' => 'required|integer', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:today', 'notes' => 'nullable|string|max:2000', 'tax_basis_points' => 'required|integer|min:0|max:10000', 'items' => 'required|array|min:1|max:100', 'items.*' => 'array:description,quantity,unit_price', 'items.*.description' => 'required|string|max:200', 'items.*.quantity' => 'required|integer|min:1|max:10000', 'items.*.unit_price' => 'required|integer|min:0|max:1000000000']);
        abort_unless(Tenant::table($r, 'contacts')->where('type', 'client')->where('archived', false)->find($d['contact_id']), 422, 'Client introuvable ou archivé.');
        $subtotal = 0;
        foreach ($d['items'] as $i) {
            $subtotal += (int) $i['quantity'] * (int) $i['unit_price'];
        }abort_unless($subtotal > 0 && $subtotal <= 1000000000000, 422, 'Montant du devis invalide.');
        $tax = intdiv($subtotal * $d['tax_basis_points'] + 5000, 10000);
        $id = DB::transaction(function () use ($r, $d, $subtotal, $tax) {
            Entitlements::write($r);
            $id = DB::table('quotes')->insertGetId([...$d, 'items' => json_encode($d['items']), 'company_id' => Tenant::company($r)->id, 'subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => $subtotal + $tax, 'created_at' => now(), 'updated_at' => now()]);
            Tenant::audit($r, 'Devis créé', 'quotes', $id);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function quoteAction(Request $r, int $id): JsonResponse
    {
        Tenant::authorize($r, 'quotes.write');
        $d = $r->validate(['action' => ['required', Rule::in(['accept', 'decline', 'convert'])]]);
        $result = DB::transaction(function () use ($r, $id, $d) {
            Entitlements::write($r);
            $q = Tenant::table($r, 'quotes')->where('id', $id)->lockForUpdate()->first();
            abort_unless($q, 404);
            if ($d['action'] === 'convert' && $q->invoice_id) {
                return $q->invoice_id;
            }
            abort_if($q->status === 'converted', 422, 'Ce devis a déjà été converti.');
            abort_if($d['action'] !== 'decline' && $q->valid_until < today()->toDateString(), 422, 'Ce devis a expiré.');
            if ($d['action'] === 'convert') {
                abort_unless($q->status === 'accepted', 422, 'Le devis doit être accepté.');
                $r->merge(['contact_id' => $q->contact_id, 'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(30)->toDateString(), 'tax_basis_points' => $q->tax_basis_points, 'notes' => $q->notes, 'items' => json_decode($q->items, true)]);
                $invoice = app(BusinessController::class)->invoice($r)->getData()->id;
                Tenant::table($r, 'quotes')->where('id', $id)->update(['status' => 'converted', 'invoice_id' => $invoice, 'updated_at' => now()]);
                Tenant::audit($r, 'Devis converti', 'quotes', $id);

                return $invoice;
            }
            abort_unless($q->status === 'draft', 422, 'Le devis a déjà reçu une décision.');
            Tenant::table($r, 'quotes')->where('id', $id)->update(['status' => $d['action'] === 'accept' ? 'accepted' : 'declined', 'updated_at' => now()]);
            Tenant::audit($r, 'Décision sur devis : '.$d['action'], 'quotes', $id);

            return null;
        });

        return response()->json(['invoice_id' => $result]);
    }

    public function purchase(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'purchases.write');
        $d = $r->validate(['supplier_id' => 'required|integer', 'reference' => 'required|string|max:100', 'description' => 'required|string|max:200', 'issue_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:issue_date', 'subtotal' => 'required|integer|min:1|max:1000000000000', 'tax_amount' => 'required|integer|min:0|max:1000000000000', 'account_code' => 'required|string|max:20', 'product_id' => 'nullable|integer', 'quantity' => 'required_with:product_id|nullable|integer|min:1|max:1000000']);
        abort_unless(Tenant::table($r, 'contacts')->where('type', 'supplier')->where('archived', false)->find($d['supplier_id']), 422, 'Fournisseur introuvable.');
        Ledger::initialize(Tenant::company($r)->id);
        abort_unless(Tenant::table($r, 'ledger_accounts')->where('code', $d['account_code'])->exists(), 422, 'Compte de charge inconnu.');
        abort_if(Tenant::table($r, 'purchases')->where('supplier_id', $d['supplier_id'])->where('reference', $d['reference'])->exists(), 422, 'Cette facture fournisseur est déjà enregistrée.');
        $id = DB::transaction(function () use ($r, $d) {
            Entitlements::write($r);
            if (! empty($d['product_id'])) {
                abort_unless(Tenant::table($r, 'products')->where('type', 'product')->where('archived', false)->find($d['product_id']), 422, 'Produit de stock actif introuvable.');
            } else {
                $d['quantity'] = null;
            }
            $company = Tenant::company($r);
            abort_if($company->closed_through && $d['issue_date'] <= $company->closed_through, 422, 'Cette période comptable est clôturée.');
            abort_if(Tenant::table($r, 'purchases')->where('supplier_id', $d['supplier_id'])->where('reference', $d['reference'])->exists(), 422, 'Cette facture fournisseur est déjà enregistrée.');
            $id = DB::table('purchases')->insertGetId([...$d, 'total' => $d['subtotal'] + $d['tax_amount'], 'company_id' => Tenant::company($r)->id, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Tenant::audit($r, 'Achat créé', 'purchases', $id);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function approvePurchase(Request $r, int $id): JsonResponse
    {
        Tenant::authorize($r, 'purchases.approve');
        DB::transaction(function () use ($r, $id) {
            Entitlements::write($r);
            $p = Tenant::table($r, 'purchases')->where('id', $id)->lockForUpdate()->first();
            abort_unless($p, 404);
            abort_unless($p->status === 'draft', 422, 'Cet achat est déjà validé.');
            $m = Ledger::mapping($p->company_id);
            Ledger::post($p->company_id, $r->user()->id, 'purchase:'.$id, $p->issue_date, 'Achat '.$p->reference, [Ledger::line($p->account_code, $p->subtotal), Ledger::line($m['tax_deductible'], $p->tax_amount), Ledger::line($m['supplier'], 0, $p->total)], 'AC');
            if ($p->product_id) {
                Inventory::move($p->company_id, $r->user()->id, $p->product_id, $p->quantity, 'purchase:'.$id, 'Réception achat '.$p->reference);
            }
            Tenant::table($r, 'purchases')->where('id', $id)->update(['status' => 'approved', 'updated_at' => now()]);
            Tenant::audit($r, 'Achat validé', 'purchases', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function payPurchase(Request $r, int $id): JsonResponse
    {
        Tenant::authorize($r, 'purchases.pay');
        $d = $r->validate(['amount' => 'required|integer|min:1|max:2000000000000', 'paid_at' => 'required|date_format:Y-m-d|before_or_equal:today', 'method' => ['required', Rule::in(['Virement', 'Espèces', 'Wave', 'Orange Money', 'Chèque'])], 'request_id' => 'required|uuid']);
        DB::transaction(function () use ($r, $id, $d) {
            Entitlements::write($r);
            $p = Tenant::table($r, 'purchases')->where('id', $id)->lockForUpdate()->first();
            abort_unless($p, 404);
            $existing = DB::table('purchase_payments')->where('request_id', $d['request_id'])->first();
            if ($existing) {
                abort_unless($existing->company_id === $p->company_id && $existing->purchase_id === $id && (int) $existing->amount === (int) $d['amount'] && $existing->paid_at === $d['paid_at'] && $existing->method === $d['method'], 409, 'Référence déjà utilisée.');

                return;
            }
            abort_unless($p->status === 'approved' && $d['paid_at'] >= $p->issue_date, 422, 'Achat non validé ou date de règlement incorrecte.');
            $paid = (int) Tenant::table($r, 'purchase_payments')->where('purchase_id', $id)->sum('amount');
            abort_if($d['amount'] > $p->total - $paid, 422, 'Le règlement dépasse le solde.');
            $payment = DB::table('purchase_payments')->insertGetId([...$d, 'company_id' => $p->company_id, 'purchase_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            Ledger::payment($p->company_id, $r->user()->id, $payment, (int) $d['amount'], $d['paid_at'], $d['method'], true);
            if ($paid + $d['amount'] === $p->total) {
                Tenant::table($r, 'purchases')->where('id', $id)->update(['status' => 'paid']);
            }Tenant::audit($r, 'Règlement fournisseur', 'purchases', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function credit(Request $r, int $id): JsonResponse
    {
        Tenant::authorize($r, 'credits.write');
        $d = $r->validate(['total' => 'required|integer|min:1|max:2000000000000', 'reason' => 'required|string|min:3|max:200', 'issued_at' => 'required|date_format:Y-m-d|before_or_equal:today', 'include_paid' => 'sometimes|boolean']);
        $credit = DB::transaction(function () use ($r, $id, $d) {
            Entitlements::write($r);
            $i = Tenant::table($r, 'invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($i, 404);
            abort_unless(in_array($i->status, ['issued', 'paid'], true) && $d['issued_at'] >= $i->issue_date, 422, 'La facture doit être validée et antérieure à l’avoir.');
            $paid = (int) Tenant::table($r, 'payments')->where('invoice_id', $id)->sum('amount');
            abort_if($d['total'] > $i->total - $i->credited - ($r->boolean('include_paid') ? 0 : $paid - (int) Tenant::table($r, 'refunds')->where('invoice_id', $id)->sum('amount')), 422, 'Le montant dépasse la part non réglée. Un remboursement de montant déjà encaissé doit être traité séparément.');
            Ledger::invoice($i, $r->user()->id);
            $newCredit = $i->credited + (int) $d['total'];
            $priorNet = (int) Tenant::table($r, 'credit_notes')->where('invoice_id', $id)->sum('subtotal');
            $net = BigInteger::of($newCredit)->multipliedBy($i->subtotal)->plus(intdiv($i->total, 2))->quotient($i->total)->toInt() - $priorNet;
            $tax = (int) $d['total'] - $net;
            unset($d['include_paid']);
            $credit = DB::table('credit_notes')->insertGetId([...$d, 'company_id' => $i->company_id, 'invoice_id' => $id, 'subtotal' => $net, 'tax_amount' => $tax, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $m = Ledger::mapping($i->company_id);
            Ledger::post($i->company_id, $r->user()->id, 'credit:'.$credit, $d['issued_at'], 'Avoir AV-'.$credit, [Ledger::line($m['sales'], $net), Ledger::line($m['tax_collected'], $tax), Ledger::line($m['customer'], 0, (int) $d['total'])], 'VT');
            Tenant::table($r, 'invoices')->where('id', $id)->update(['credited' => $newCredit, 'status' => $paid - (int) Tenant::table($r, 'refunds')->where('invoice_id', $id)->sum('amount') + $newCredit >= $i->total ? 'paid' : 'issued', 'updated_at' => now()]);
            Tenant::audit($r, 'Avoir AV-'.$credit.' créé', 'invoices', $id);

            return $credit;
        });

        return response()->json(['id' => $credit], 201);
    }

    public function stock(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'stock.write');
        $d = $r->validate(['product_id' => 'required|integer', 'quantity' => 'required|integer|not_in:0|min:-1000000|max:1000000', 'reason' => 'required|string|min:3|max:200']);
        DB::transaction(function () use ($r, $d) {
            Entitlements::write($r);
            $p = Tenant::table($r, 'products')->where('id', $d['product_id'])->lockForUpdate()->first();
            abort_unless($p && ! $p->archived && $p->type === 'product', 422, 'Produit actif introuvable.');
            $stock = (int) Tenant::table($r, 'stock_movements')->where('product_id', $p->id)->sum('quantity');
            abort_if($stock + $d['quantity'] < 0, 422, 'Le stock disponible est insuffisant.');
            $id = DB::table('stock_movements')->insertGetId([...$d, 'company_id' => $p->company_id, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Tenant::audit($r, 'Mouvement de stock', 'stock_movements', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function approveExpense(Request $r, int $id): JsonResponse
    {
        Tenant::authorize($r, 'expenses.approve');
        $d = $r->validate(['action' => ['required', Rule::in(['approve', 'reject'])]]);
        DB::transaction(function () use ($r, $id, $d) {
            Entitlements::write($r);
            $e = Tenant::table($r, 'expenses')->where('id', $id)->lockForUpdate()->first();
            abort_unless($e, 404);
            abort_unless($e->status === 'pending', 422, 'Cette dépense a déjà été traitée.');
            if ($d['action'] === 'approve') {
                Ledger::expense($e, $r->user()->id);
            }Tenant::table($r, 'expenses')->where('id', $id)->update(['status' => $d['action'] === 'approve' ? 'approved' : 'rejected', 'updated_at' => now()]);
            Tenant::audit($r, 'Dépense '.$d['action'], 'expenses', $id);
        });

        return response()->json(['ok' => true]);
    }
}
