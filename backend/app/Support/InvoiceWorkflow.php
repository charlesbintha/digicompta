<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceWorkflow
{
    public static function fallback(): array
    {
        return ['steps' => [['role' => 'any', 'name' => 'Validation', 'role_name' => 'Validateur autorisé']], 'prevent_self_approval' => false, 'distinct_approvers' => false];
    }

    public static function snapshot(object $invoice): array
    {
        return $invoice->approval_snapshot ? json_decode($invoice->approval_snapshot, true) : self::fallback();
    }

    public static function configuration(int $companyId): array
    {
        $row = DB::table('invoice_workflows')->where('company_id', $companyId)->first();

        return $row ? ['enabled' => (bool) $row->enabled, 'threshold' => (int) $row->threshold,
            'prevent_self_approval' => (bool) $row->prevent_self_approval, 'distinct_approvers' => (bool) $row->distinct_approvers, 'steps' => json_decode($row->steps, true)]
            : ['enabled' => false, 'threshold' => 0, 'prevent_self_approval' => false, 'distinct_approvers' => true, 'steps' => [['role' => 'accountant', 'name' => 'Contrôle comptable']]];
    }

    public static function forSubmission(object $invoice): array
    {
        $config = self::configuration($invoice->company_id);
        if (! $config['enabled']) {
            return self::fallback();
        }
        $steps = $invoice->total >= $config['threshold'] ? $config['steps'] : self::fallback()['steps'];
        $roles = collect(Tenant::roleOptions($invoice->company_id))->keyBy('key');
        foreach ($steps as &$step) {
            $step['role_name'] = $roles->get($step['role'])['name'] ?? 'Validateur autorisé';
        }
        unset($step);
        $snapshot = ['steps' => $steps, 'prevent_self_approval' => $config['prevent_self_approval'], 'distinct_approvers' => $config['distinct_approvers']];
        $members = DB::table('memberships')->where('company_id', $invoice->company_id)->where('is_active', true)->get()
            ->filter(fn (object $member) => in_array('invoices.approve', Tenant::permissions($member->role, $invoice->company_id), true))
            ->filter(fn (object $member) => ! $snapshot['prevent_self_approval'] || $member->user_id !== $invoice->created_by);
        foreach (collect($steps)->countBy('role') as $role => $count) {
            $eligible = $role === 'any' ? $members : $members->where('role', $role);
            abort_if($eligible->count() < ($snapshot['distinct_approvers'] ? $count : 1), 422, 'Aucun circuit réalisable : attribuez les rôles requis à suffisamment de validateurs actifs, distincts du créateur si nécessaire.');
        }

        return $snapshot;
    }

    public static function canDecide(Request $request, object $invoice, array $history = []): bool
    {
        if ($invoice->status !== 'pending' || ! Tenant::allows($request, 'invoices.approve')) {
            return false;
        }
        $snapshot = self::snapshot($invoice);
        $step = $snapshot['steps'][$invoice->approval_step] ?? null;
        if (! $step || ($step['role'] !== 'any' && $step['role'] !== Tenant::role($request))) {
            return false;
        }
        if ($snapshot['prevent_self_approval'] && $invoice->created_by === $request->user()->id) {
            return false;
        }
        if ($snapshot['distinct_approvers']) {
            foreach ($history as $decision) {
                if ($decision->round === $invoice->approval_round && $decision->user_id === $request->user()->id && $decision->action === 'approve') {
                    return false;
                }
            }
        }

        return true;
    }

    public static function transition(Request $request, int $id, array $data): void
    {
        DB::transaction(function () use ($request, $id, $data) {
            $company = DB::table('companies')->where('id', Tenant::company($request)->id)->lockForUpdate()->first();
            Tenant::authorize($request, $data['action'] === 'submit' ? 'invoices.write' : 'invoices.approve');
            $invoice = Tenant::table($request, 'invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_if($invoice->archived, 422, 'Restaurez ce brouillon avant de le soumettre.');
            abort_unless($invoice->status === ($data['action'] === 'submit' ? 'draft' : 'pending'), 422, 'Cette action ne correspond pas au statut actuel.');
            if ($data['action'] === 'submit') {
                $snapshot = self::forSubmission($invoice);
                Tenant::table($request, 'invoices')->where('id', $id)->update(['status' => 'pending', 'approval_snapshot' => json_encode($snapshot), 'approval_round' => $invoice->approval_round + 1, 'approval_step' => 0, 'updated_at' => now()]);
                Tenant::audit($request, 'Soumise à validation', 'invoices', $id);
                Communications::notifyPermission($company->id, 'invoices.approve', 'Validation demandée', 'Une facture attend une validation.', 'invoices');

                return;
            }
            abort_unless((int) $data['round'] === $invoice->approval_round && (int) $data['step'] === $invoice->approval_step, 409, 'La validation a déjà évolué. Actualisez la facture avant de continuer.');
            $history = DB::table('invoice_approvals')->where('invoice_id', $id)->get()->all();
            abort_unless(self::canDecide($request, $invoice, $history), 403, 'Vous ne pouvez pas intervenir sur cette étape de validation.');
            $snapshot = self::snapshot($invoice);
            $step = $snapshot['steps'][$invoice->approval_step];
            DB::table('invoice_approvals')->insert(['company_id' => $company->id, 'invoice_id' => $id, 'user_id' => $request->user()->id,
                'round' => $invoice->approval_round, 'step' => $invoice->approval_step, 'step_name' => $step['name'], 'action' => $data['action'], 'comment' => $data['comment'] ?? null, 'created_at' => now()]);
            $update = ['updated_at' => now()];
            if ($data['action'] === 'reject') {
                $update['status'] = 'draft';
                $message = 'Retour en brouillon : '.$step['name'];
            } elseif ($invoice->approval_step + 1 < count($snapshot['steps'])) {
                $update['approval_step'] = $invoice->approval_step + 1;
                $message = 'Étape validée : '.$step['name'];
            } else {
                $sequence = $company->invoice_sequence + 1;
                DB::table('companies')->where('id', $company->id)->update(['invoice_sequence' => $sequence]);
                $update = [...$update, 'status' => 'issued', 'number' => 'FAC-'.substr($invoice->issue_date, 0, 4).'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT), 'approved_by' => $request->user()->id];
                $invoice->number = $update['number'];
                Inventory::issue($invoice, $request->user()->id);
                Ledger::invoice($invoice, $request->user()->id);
                $message = 'Facture validée';
            }
            Tenant::table($request, 'invoices')->where('id', $id)->update($update);
            Tenant::audit($request, $message, 'invoices', $id);
            Communications::notifyPermission($company->id, 'invoices.read', $message, 'Le traitement de la facture a évolué.', 'invoices');
        });
    }
}
