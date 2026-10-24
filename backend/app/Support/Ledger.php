<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Ledger
{
    public static function sync(int $company, int $actor): void
    {
        foreach (DB::table('invoices')->where('company_id', $company)->whereIn('status', ['issued', 'paid'])->get() as $i) {
            self::invoice($i, $actor);
        }
        foreach (DB::table('payments')->where('company_id', $company)->get() as $p) {
            self::payment($company, $actor, $p->id, $p->amount, $p->paid_at, $p->method);
        }
        foreach (DB::table('expenses')->where('company_id', $company)->where('status', 'approved')->get() as $e) {
            self::expense($e, $actor);
        }
    }

    public static function defaults(): array
    {
        return ['customer' => '411', 'supplier' => '401', 'sales' => '706', 'tax_collected' => '443', 'tax_deductible' => '445', 'bank' => '521', 'cash' => '571', 'expense' => '601'];
    }

    public static function initialize(int $company): void
    {
        foreach (['401' => 'Fournisseurs', '411' => 'Clients', '443' => 'État, TVA facturée', '445' => 'État, TVA récupérable', '521' => 'Banques', '571' => 'Caisse', '601' => 'Achats de marchandises', '701' => 'Ventes de marchandises', '706' => 'Services vendus'] as $code => $name) {
            DB::table('ledger_accounts')->insertOrIgnore(['company_id' => $company, 'code' => $code, 'name' => $name]);
        }
    }

    public static function mapping(int $company): array
    {
        return [...self::defaults(), ...json_decode(DB::table('companies')->where('id', $company)->value('ledger_settings') ?? '{}', true)];
    }

    public static function post(int $company, int $actor, string $source, string $date, string $label, array $lines, string $journal = 'OD', ?int $reversal = null): int
    {
        return DB::transaction(function () use ($company, $actor, $source, $date, $label, $lines, $journal, $reversal) {
            $row = DB::table('companies')->where('id', $company)->lockForUpdate()->first();
            $existing = DB::table('journal_entries')->where('company_id', $company)->where('source_key', $source)->value('id');
            if ($existing) {
                return $existing;
            }
            abort_if($row->closed_through && $date <= $row->closed_through, 422, 'Cette période comptable est clôturée.');
            self::initialize($company);
            $debit = 0;
            $credit = 0;
            foreach ($lines as $line) {
                abort_unless(DB::table('ledger_accounts')->where('company_id', $company)->where('code', $line['account_code'])->exists(), 422, 'Compte comptable introuvable.');
                abort_if(DB::table('bank_statements')->where('company_id', $company)->where('account_code', $line['account_code'])->where('status', 'reconciled')->where('to', '>=', $date)->exists(), 422, 'Ce compte bancaire est déjà rapproché à cette date. Utilisez une date ultérieure.');
                abort_if($line['debit'] < 0 || $line['credit'] < 0 || ($line['debit'] > 0 && $line['credit'] > 0), 422, 'Une ligne doit être au débit ou au crédit.');
                $debit += $line['debit'];
                $credit += $line['credit'];
            }
            abort_unless($debit === $credit && $debit > 0, 422, 'Les écritures doivent être équilibrées et non nulles.');
            $id = DB::table('journal_entries')->insertGetId(['company_id' => $company, 'created_by' => $actor, 'source_key' => $source, 'entry_date' => $date, 'label' => $label, 'journal' => $journal, 'reversal_of' => $reversal, 'created_at' => now()]);
            foreach ($lines as $line) {
                if ($line['debit'] || $line['credit']) {
                    DB::table('journal_lines')->insert([...$line, 'entry_id' => $id]);
                }
            }

            return $id;
        });
    }

    public static function line(string $account, int $debit = 0, int $credit = 0): array
    {
        return ['account_code' => $account, 'debit' => $debit, 'credit' => $credit];
    }

    public static function invoice(object $invoice, int $actor): void
    {
        $m = self::mapping($invoice->company_id);
        self::post($invoice->company_id, $actor, 'invoice:'.$invoice->id, $invoice->issue_date, 'Facture '.$invoice->number, [self::line($m['customer'], $invoice->total), self::line($m['sales'], 0, $invoice->subtotal), self::line($m['tax_collected'], 0, $invoice->tax_amount)], 'VT');
    }

    public static function payment(int $company, int $actor, int $id, int $amount, string $date, string $method, bool $supplier = false): void
    {
        $m = self::mapping($company);
        $cash = $method === 'Espèces' ? $m['cash'] : $m['bank'];
        self::post($company, $actor, ($supplier ? 'purchase-payment:' : 'payment:').$id, $date, $supplier ? 'Règlement fournisseur' : 'Règlement client', $supplier ? [self::line($m['supplier'], $amount), self::line($cash, 0, $amount)] : [self::line($cash, $amount), self::line($m['customer'], 0, $amount)], 'BQ');
    }

    public static function expense(object $expense, int $actor): void
    {
        $m = self::mapping($expense->company_id);
        self::post($expense->company_id, $actor, 'expense:'.$expense->id, $expense->paid_at, $expense->description, [self::line($expense->account_code, $expense->amount), self::line($m['bank'], 0, $expense->amount)], 'AC');
    }
}
