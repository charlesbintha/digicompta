<?php

namespace App\Http\Controllers;

use App\Support\Entitlements;
use App\Support\Ledger;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingController extends Controller
{
    public function sync(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'accounting.write');
        DB::transaction(function () use ($r) {
            Entitlements::write($r);
            Ledger::sync(Tenant::company($r)->id, $r->user()->id);
            Tenant::audit($r, 'Historique comptabilisé', 'companies', Tenant::company($r)->id);
        });

        return response()->json(['ok' => true]);
    }

    public function index(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'accounting.read');
        $company = Tenant::company($r);
        Ledger::initialize($company->id);
        $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $query = Tenant::table($r, 'journal_entries')->orderByDesc('entry_date')->orderByDesc('id');
        if ($r->filled('from')) {
            $query->where('entry_date', '>=', $r->from);
        }if ($r->filled('to')) {
            $query->where('entry_date', '<=', $r->to);
        }
        $entries = $query->get();
        $lines = DB::table('journal_lines')->whereIn('entry_id', $entries->pluck('id'))->get();
        foreach ($entries as $entry) {
            $entry->lines = $lines->where('entry_id', $entry->id)->values();
        }
        $accounts = Tenant::table($r, 'ledger_accounts')->orderBy('code')->get();
        foreach ($accounts as $account) {
            $account->debit = (int) $lines->where('account_code', $account->code)->sum('debit');
            $account->credit = (int) $lines->where('account_code', $account->code)->sum('credit');
        }

        return response()->json(['accounts' => $accounts, 'entries' => $entries, 'mapping' => Ledger::mapping($company->id), 'closed_through' => $company->closed_through]);
    }

    public function account(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'accounting.write');
        $data = $r->validate(['code' => 'required|regex:/^[1-9][0-9]{2,11}$/', 'name' => 'required|string|max:150']);
        Ledger::initialize(Tenant::company($r)->id);
        abort_if(Tenant::table($r, 'ledger_accounts')->where('code', $data['code'])->exists(), 422, 'Ce compte existe déjà.');
        DB::table('ledger_accounts')->insert([...$data, 'company_id' => Tenant::company($r)->id]);

        return response()->json(['ok' => true], 201);
    }

    public function entry(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'accounting.write');
        $d = $r->validate(['label' => 'required|string|max:200', 'entry_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'journal' => ['required', Rule::in(['OD', 'VT', 'AC', 'BQ', 'CA'])], 'lines' => 'required|array|min:2|max:100', 'lines.*' => 'array:account_code,debit,credit', 'lines.*.account_code' => 'required|string', 'lines.*.debit' => 'required|integer|min:0|max:2000000000000', 'lines.*.credit' => 'required|integer|min:0|max:2000000000000']);
        $lines = array_map(fn (array $l) => Ledger::line($l['account_code'], (int) $l['debit'], (int) $l['credit']), $d['lines']);
        $id = DB::transaction(function () use ($r, $d, $lines) {
            Entitlements::write($r);
            $id = Ledger::post(Tenant::company($r)->id, $r->user()->id, 'manual:'.Str::uuid(), $d['entry_date'], $d['label'], $lines, $d['journal']);
            Tenant::audit($r, 'Écriture comptable créée', 'journal_entries', $id);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function reverse(Request $r, int $id): JsonResponse
    {
        Tenant::authorize($r, 'accounting.write');
        $d = $r->validate(['entry_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'reason' => 'required|string|min:3|max:150']);
        DB::transaction(function () use ($r, $id, $d) {
            Entitlements::write($r);
            $entry = Tenant::table($r, 'journal_entries')->where('id', $id)->lockForUpdate()->first();
            abort_unless($entry, 404);
            abort_unless(str_starts_with($entry->source_key, 'manual:'), 422, 'Corrigez les opérations commerciales depuis leur module (avoir ou règlement).');
            abort_if(DB::table('journal_entries')->where('reversal_of', $id)->exists(), 422, 'Cette écriture est déjà extournée.');
            abort_if($d['entry_date'] < $entry->entry_date, 422, 'La correction ne peut pas précéder l’écriture.');
            $lines = DB::table('journal_lines')->where('entry_id', $id)->get()->map(fn ($l) => Ledger::line($l->account_code, $l->credit, $l->debit))->all();
            Ledger::post($entry->company_id, $r->user()->id, 'reversal:'.$id, $d['entry_date'], $d['reason'], $lines, 'OD', $id);
            Tenant::audit($r, 'Écriture extournée', 'journal_entries', $id);
        });

        return response()->json(['ok' => true]);
    }

    public function settings(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'accounting.close');
        $d = $r->validate(['mapping' => 'required|array:customer,supplier,sales,tax_collected,tax_deductible,bank,cash,expense', 'mapping.*' => 'required|string']);
        abort_unless(count($d['mapping']) === count(Ledger::defaults()), 422, 'Tous les comptes de correspondance sont requis.');
        Ledger::initialize(Tenant::company($r)->id);
        foreach ($d['mapping'] as $code) {
            abort_unless(Tenant::table($r, 'ledger_accounts')->where('code', $code)->exists(), 422, 'Compte inconnu.');
        }
        DB::transaction(function () use ($r, $d) {
            Entitlements::write($r);
            abort_if(Tenant::table($r, 'journal_entries')->exists(), 422, 'Les correspondances sont figées après la première écriture pour préserver la cohérence des comptes.');
            DB::table('companies')->where('id', Tenant::company($r)->id)->update(['ledger_settings' => json_encode($d['mapping'])]);
        });
        Tenant::audit($r, 'Correspondances comptables modifiées', 'companies', Tenant::company($r)->id);

        return response()->json(['ok' => true]);
    }

    public function closePeriod(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'accounting.close');
        $d = $r->validate(['through' => 'required|date_format:Y-m-d|before_or_equal:today']);
        DB::transaction(function () use ($r, $d) {
            Entitlements::write($r);
            $company = Tenant::company($r);
            Ledger::sync($company->id, $r->user()->id);
            abort_if($company->closed_through && $d['through'] <= $company->closed_through, 422, 'Cette période est déjà clôturée.');
            abort_if(Tenant::table($r, 'invoices')->whereIn('status', ['draft', 'pending'])->where('archived', false)->where('issue_date', '<=', $d['through'])->exists() || Tenant::table($r, 'expenses')->where('status', 'pending')->where('paid_at', '<=', $d['through'])->exists() || Tenant::table($r, 'purchases')->where('status', 'draft')->where('issue_date', '<=', $d['through'])->exists(), 422, 'Finalisez les opérations en attente avant la clôture.');
            DB::table('companies')->where('id', $company->id)->update(['closed_through' => $d['through']]);
            Tenant::audit($r, 'Période clôturée au '.$d['through'], 'companies', $company->id);
        });

        return response()->json(['ok' => true]);
    }

    public function export(Request $r): StreamedResponse
    {
        Tenant::authorize($r, 'accounting.read');
        $rows = Tenant::table($r, 'journal_entries')->join('journal_lines', 'journal_lines.entry_id', '=', 'journal_entries.id')->orderBy('entry_date')->select('entry_date', 'journal', 'label', 'account_code', 'debit', 'credit')->get();

        return response()->streamDownload(function () use ($rows) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Date', 'Journal', 'Libellé', 'Compte', 'Débit XOF', 'Crédit XOF'], ';', '"', '');
            foreach ($rows as $row) {
                $values = array_values((array) $row);
                $values = array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-]/', $v) ? "'".$v : $v, $values);
                fputcsv($f, $values, ';', '"', '');
            }fclose($f);
        }, 'journal-comptable.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
