<?php

namespace App\Http\Controllers;

use App\Support\Entitlements;
use App\Support\Inventory;
use App\Support\Ledger;
use App\Support\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TreasuryController extends Controller
{
    public function refunds(Request $request): JsonResponse
    {
        Tenant::authorize($request, 'refunds.read');

        return response()->json(['refunds' => Tenant::table($request, 'refunds')->orderByDesc('id')->get()]);
    }

    public function refund(Request $request, int $id): JsonResponse
    {
        Tenant::authorize($request, 'refunds.write');
        $data = $request->validate(['amount' => 'required|integer|min:1|max:2000000000000', 'paid_at' => 'required|date_format:Y-m-d|before_or_equal:today', 'method' => ['required', Rule::in(['Virement', 'Espèces', 'Wave', 'Orange Money', 'Chèque'])], 'reference' => 'required|string|min:3|max:150', 'request_id' => 'required|uuid']);
        DB::transaction(function () use ($request, $id, $data) {
            Entitlements::write($request);
            $invoice = Tenant::table($request, 'invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            $existing = DB::table('refunds')->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->company_id === $invoice->company_id && $existing->invoice_id === $id && (int) $existing->amount === (int) $data['amount'] && $existing->paid_at === $data['paid_at'] && $existing->method === $data['method'] && $existing->reference === $data['reference'], 409, 'Référence de requête déjà utilisée.');

                return;
            }
            $paid = (int) Tenant::table($request, 'payments')->where('invoice_id', $id)->sum('amount');
            $refunded = (int) Tenant::table($request, 'refunds')->where('invoice_id', $id)->sum('amount');
            $due = max(0, $paid - $refunded - ($invoice->total - $invoice->credited));
            abort_unless(in_array($invoice->status, ['issued', 'paid'], true) && (int) $data['amount'] <= $due, 422, 'Le remboursement dépasse le crédit disponible du client. Émettez d’abord un avoir.');
            $latestCredit = Tenant::table($request, 'credit_notes')->where('invoice_id', $id)->max('issued_at');
            $latestPayment = Tenant::table($request, 'payments')->where('invoice_id', $id)->max('paid_at');
            abort_if($data['paid_at'] < max($latestCredit ?? $invoice->issue_date, $latestPayment ?? $invoice->issue_date), 422, 'Le remboursement ne peut pas précéder les avoirs et encaissements concernés.');
            Ledger::sync($invoice->company_id, $request->user()->id);
            $refund = DB::table('refunds')->insertGetId([...$data, 'company_id' => $invoice->company_id, 'invoice_id' => $id, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $mapping = Ledger::mapping($invoice->company_id);
            Ledger::post($invoice->company_id, $request->user()->id, 'refund:'.$refund, $data['paid_at'], 'Remboursement '.$invoice->number, [Ledger::line($mapping['customer'], (int) $data['amount']), Ledger::line($data['method'] === 'Espèces' ? $mapping['cash'] : $mapping['bank'], 0, (int) $data['amount'])], $data['method'] === 'Espèces' ? 'CA' : 'BQ');
            Tenant::audit($request, 'Remboursement enregistré', 'refunds', $refund);
        });

        return response()->json(['ok' => true]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        Tenant::authorize($request, 'invoices.write');
        $data = $request->validate(['archived' => 'required|boolean']);
        DB::transaction(function () use ($request, $id, $data) {
            Entitlements::write($request);
            $invoice = Tenant::table($request, 'invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_unless($invoice->status === 'draft', 422, 'Seuls les brouillons peuvent être archivés ou restaurés.');
            Tenant::table($request, 'invoices')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            Tenant::audit($request, $data['archived'] ? 'Brouillon archivé' : 'Brouillon restauré', 'invoices', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function stockReturn(Request $request, int $id): JsonResponse
    {
        Tenant::authorize($request, 'stock.write');
        $data = $request->validate(['product_id' => 'required|integer', 'quantity' => 'required|integer|min:1|max:1000000', 'reason' => 'required|string|min:3|max:180', 'request_id' => 'required|uuid']);
        DB::transaction(function () use ($request, $id, $data) {
            Entitlements::write($request);
            $invoice = Tenant::table($request, 'invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            $source = 'return:'.$data['request_id'];
            $existing = Tenant::table($request, 'stock_movements')->where('source_key', $source)->first();
            if ($existing) {
                abort_unless($existing->invoice_id === $id && $existing->product_id === (int) $data['product_id'] && $existing->quantity === (int) $data['quantity'] && $existing->reason === $data['reason'], 409, 'Référence de retour déjà utilisée.');

                return;
            }
            $movements = Tenant::table($request, 'stock_movements')->where('invoice_id', $id)->where('product_id', $data['product_id']);
            $remaining = -(int) $movements->sum('quantity');
            abort_if((int) $data['quantity'] > $remaining, 422, 'Le retour dépasse la quantité vendue non encore retournée.');
            Inventory::move($invoice->company_id, $request->user()->id, (int) $data['product_id'], (int) $data['quantity'], $source, $data['reason'], $id);
            Tenant::audit($request, 'Retour de marchandise', 'invoices', $id);
        });

        return response()->json(['ok' => true]);
    }

    private function ledgerLines(int $company, string $account): Builder
    {
        return DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')->where('journal_entries.company_id', $company)->where('account_code', $account);
    }

    public function banking(Request $request): JsonResponse
    {
        Tenant::authorize($request, 'banking.read');
        $company = Tenant::company($request)->id;
        Ledger::initialize($company);
        $statements = Tenant::table($request, 'bank_statements')->orderByDesc('to')->get();
        foreach ($statements as $statement) {
            $statement->lines = DB::table('bank_statement_lines')->where('statement_id', $statement->id)->orderBy('booked_at')->orderBy('id')->get();
            $statement->ledger_opening = (int) $this->ledgerLines($company, $statement->account_code)->where('entry_date', '<', $statement->from)->sum(DB::raw('debit-credit'));
            $statement->ledger_closing = (int) $this->ledgerLines($company, $statement->account_code)->where('entry_date', '<=', $statement->to)->sum(DB::raw('debit-credit'));
            $statement->candidates = $this->ledgerLines($company, $statement->account_code)->whereBetween('entry_date', [$statement->from, $statement->to])->whereNotIn('journal_lines.id', DB::table('bank_statement_lines')->whereNotNull('journal_line_id')->select('journal_line_id'))->select('journal_lines.id', 'entry_date', 'label', 'debit', 'credit')->orderBy('entry_date')->get();
        }

        return response()->json(['statements' => $statements, 'accounts' => Tenant::table($request, 'ledger_accounts')->where('code', 'like', '52%')->orderBy('code')->get()]);
    }

    public function statement(Request $request): JsonResponse
    {
        Tenant::authorize($request, 'banking.write');
        $data = $request->validate(['reference' => 'required|string|max:100', 'account_code' => 'required|string|max:20', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:today', 'opening_balance' => 'required|integer|min:-2000000000000|max:2000000000000', 'closing_balance' => 'required|integer|min:-2000000000000|max:2000000000000', 'lines' => 'present|array|max:2000', 'lines.*' => 'array:booked_at,description,amount', 'lines.*.booked_at' => 'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:to', 'lines.*.description' => 'required|string|max:200', 'lines.*.amount' => 'required|integer|not_in:0|min:-2000000000000|max:2000000000000']);
        $id = DB::transaction(function () use ($request, $data) {
            Entitlements::write($request);
            $company = Tenant::company($request)->id;
            abort_unless(str_starts_with($data['account_code'], '52') && Tenant::table($request, 'ledger_accounts')->where('code', $data['account_code'])->exists(), 422, 'Sélectionnez un compte bancaire de votre plan comptable.');
            abort_if(Tenant::table($request, 'bank_statements')->where('account_code', $data['account_code'])->where(function (Builder $query) use ($data) {
                $query->where('reference', $data['reference'])->orWhere(function (Builder $period) use ($data) {
                    $period->where('status', '!=', 'cancelled')->where('from', '<=', $data['to'])->where('to', '>=', $data['from']);
                });
            })->exists(), 422, 'Un relevé de même référence ou couvrant cette période existe déjà.');
            $sum = array_sum(array_map(fn (array $line) => (int) $line['amount'], $data['lines']));
            abort_unless((int) $data['opening_balance'] + $sum === (int) $data['closing_balance'], 422, 'Le solde initial augmenté des mouvements doit égaler le solde final.');
            $lines = $data['lines'];
            unset($data['lines']);
            $id = DB::table('bank_statements')->insertGetId([...$data, 'company_id' => $company, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $line) {
                DB::table('bank_statement_lines')->insert([...$line, 'statement_id' => $id]);
            }
            Tenant::audit($request, 'Relevé bancaire importé', 'bank_statements', $id);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function matchLine(Request $request, int $id, int $line): JsonResponse
    {
        Tenant::authorize($request, 'banking.write');
        $data = $request->validate(['journal_line_id' => 'present|nullable|integer']);
        DB::transaction(function () use ($request, $id, $line, $data) {
            Entitlements::write($request);
            $statement = Tenant::table($request, 'bank_statements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($statement, 404);
            abort_unless($statement->status === 'draft', 422, 'Ce rapprochement est déjà finalisé.');
            $bank = DB::table('bank_statement_lines')->where('statement_id', $id)->where('id', $line)->lockForUpdate()->first();
            abort_unless($bank, 404);
            if ($data['journal_line_id'] !== null) {
                $entry = $this->ledgerLines($statement->company_id, $statement->account_code)->where('journal_lines.id', $data['journal_line_id'])->whereBetween('entry_date', [$statement->from, $statement->to])->select('journal_lines.*')->first();
                abort_unless($entry && (int) $entry->debit - (int) $entry->credit === (int) $bank->amount, 422, 'Le compte, la période ou le montant de cette écriture ne correspond pas.');
                abort_if(DB::table('bank_statement_lines')->where('journal_line_id', $entry->id)->where('id', '!=', $line)->exists(), 422, 'Cette écriture est déjà rapprochée.');
            }
            DB::table('bank_statement_lines')->where('id', $line)->update($data);
            Tenant::audit($request, $data['journal_line_id'] ? 'Ligne bancaire rapprochée' : 'Rapprochement retiré', 'bank_statements', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function cancelStatement(Request $request, int $id): JsonResponse
    {
        Tenant::authorize($request, 'banking.write');
        DB::transaction(function () use ($request, $id) {
            Entitlements::write($request);
            $statement = Tenant::table($request, 'bank_statements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($statement, 404);
            abort_unless($statement->status === 'draft', 422, 'Seul un relevé en cours peut être annulé.');
            DB::table('bank_statement_lines')->where('statement_id', $id)->update(['journal_line_id' => null]);
            Tenant::table($request, 'bank_statements')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            Tenant::audit($request, 'Relevé annulé, associations libérées', 'bank_statements', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function reconcile(Request $request, int $id): JsonResponse
    {
        Tenant::authorize($request, 'banking.write');
        DB::transaction(function () use ($request, $id) {
            Entitlements::write($request);
            $statement = Tenant::table($request, 'bank_statements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($statement, 404);
            abort_unless($statement->status === 'draft', 409, 'Relevé déjà rapproché.');
            abort_if(DB::table('bank_statement_lines')->where('statement_id', $id)->whereNull('journal_line_id')->exists(), 422, 'Rapprochez toutes les lignes du relevé.');
            $ledger = $this->ledgerLines($statement->company_id, $statement->account_code);
            $opening = (int) (clone $ledger)->where('entry_date', '<', $statement->from)->sum(DB::raw('debit-credit'));
            $closing = (int) (clone $ledger)->where('entry_date', '<=', $statement->to)->sum(DB::raw('debit-credit'));
            $unmatched = (clone $ledger)->whereBetween('entry_date',[$statement->from, $statement->to])->whereNotIn('journal_lines.id',DB::table('bank_statement_lines')->where('statement_id',$id)->whereNotNull('journal_line_id')->select('journal_line_id'))->exists();
            abort_if($unmatched || $opening !== (int) $statement->opening_balance || $closing !== (int) $statement->closing_balance,422,'Les écritures et les soldes comptables ne correspondent pas au relevé. Comptabilisez les opérations manquantes avant de finaliser.');
            Tenant::table($request,'bank_statements')->where('id',$id)->update(['status' => 'reconciled', 'reconciled_at' => now(), 'updated_at' => now()]);
            Tenant::audit($request,'Rapprochement bancaire finalisé','bank_statements',$id);
        });

        return response()->json(['ok' => true]);
    }
}
