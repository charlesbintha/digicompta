<?php

namespace App\Http\Controllers;

use App\Support\Communications;
use App\Support\Entitlements;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupportController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $staff = $r->is('api/v1/admin/*');
        if ($staff) {
            abort_unless($r->user()->is_super_admin, 403);
        } else {
            Tenant::authorize($r, 'support.read');
        }
        $q = DB::table('support_tickets')->join('companies', 'companies.id', '=', 'support_tickets.company_id')->leftJoin('users as assignee', 'assignee.id', '=', 'support_tickets.assigned_to')->select('support_tickets.*', 'companies.name as company_name', 'assignee.name as assignee_name');
        if (! $staff) {
            $q->where('support_tickets.company_id', Tenant::company($r)->id);
        }$tickets = $q->orderByDesc('support_tickets.id')->limit(200)->get();
        $replies = DB::table('ticket_replies')->join('users', 'users.id', '=', 'ticket_replies.user_id')->whereIn('ticket_id', $tickets->pluck('id'))->select('ticket_replies.*', 'users.name as author')->orderBy('ticket_replies.id')->get()->groupBy('ticket_id');
        foreach ($tickets as $t) {
            $t->replies = $replies->get($t->id, collect());
            $t->response_breached = ! $t->responded_at && now()->gt($t->response_due_at);
            $t->resolution_breached = ! $t->resolved_at && now()->gt($t->resolution_due_at);
        }

        return response()->json(['tickets' => $tickets, 'staff' => $staff ? DB::table('users')->where('is_super_admin', true)->select('id', 'name')->get() : []]);
    }

    public function create(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'support.write');
        $d = $r->validate(['subject' => 'required|string|max:180', 'body' => 'required|string|min:10|max:10000', 'category' => ['required', Rule::in(['bug', 'question', 'billing'])], 'priority' => ['required', Rule::in(['normal', 'high', 'critical'])]]);
        $company = Tenant::company($r);
        $plan = Entitlements::plan($company);
        $factor = match ($d['priority']) {
            'critical' => 0.25,'high' => 0.5,default => 1
        };
        $id = DB::transaction(function () use ($r, $d, $company, $plan, $factor) {
            $id = DB::table('support_tickets')->insertGetId(['company_id' => $company->id, 'created_by' => $r->user()->id, 'subject' => $d['subject'], 'category' => $d['category'], 'priority' => $d['priority'], 'response_due_at' => now()->addMinutes((int) (($plan?->sla_response_hours ?? 24) * 60 * $factor)), 'resolution_due_at' => now()->addMinutes((int) (($plan?->sla_resolution_hours ?? 72) * 60 * $factor)), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ticket_replies')->insert(['ticket_id' => $id, 'user_id' => $r->user()->id, 'body' => $d['body'], 'is_staff' => false, 'created_at' => now()]);
            Tenant::audit($r, 'Signalement créé', 'support_tickets', $id);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function reply(Request $r, int $id): JsonResponse
    {
        $staff = $r->is('api/v1/admin/*');
        if ($staff) {
            abort_unless($r->user()->is_super_admin, 403);
        } else {
            Tenant::authorize($r, 'support.write');
        }$d = $r->validate(['body' => 'required|string|min:1|max:10000']);
        DB::transaction(function () use ($r, $id, $d, $staff) {
            $q = DB::table('support_tickets')->where('id', $id);
            if (! $staff) {
                $q->where('company_id', Tenant::company($r)->id);
            }$t = $q->lockForUpdate()->first();
            abort_unless($t, 404);
            abort_if($t->status === 'closed', 422, 'Ce ticket est fermé. Créez un nouveau signalement.');
            DB::table('ticket_replies')->insert(['ticket_id' => $id, 'user_id' => $r->user()->id, 'body' => $d['body'], 'is_staff' => $staff, 'created_at' => now()]);
            $u = ['updated_at' => now()];
            if ($staff && ! $t->responded_at) {
                $u['responded_at'] = now();
            }if ($t->status === 'resolved') {
                $u['status'] = 'open';
                $u['resolved_at'] = null;
                $u['sla_alerted_at'] = null;
            }DB::table('support_tickets')->where('id', $id)->update($u);
            if ($staff) {
                Communications::notifyPermission($t->company_id, 'support.read', 'Réponse du support', 'Une réponse est disponible pour le ticket #'.$id.'.', 'support');
            }
        });

        return response()->json(['ok' => true]);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        abort_unless($r->user()->is_super_admin, 403);
        $d = $r->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'resolved', 'closed'])], 'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_super_admin', true)]]);
        DB::transaction(function () use ($r, $id, $d) {
            $t = DB::table('support_tickets')->where('id', $id)->lockForUpdate()->first();
            abort_unless($t, 404);
            $resolved = in_array($d['status'], ['resolved', 'closed'], true);
            DB::table('support_tickets')->where('id', $id)->update([...$d, 'resolved_at' => $resolved ? ($t->resolved_at ?? now()) : null, 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['company_id' => $t->company_id, 'user_id' => $r->user()->id, 'action' => 'Ticket : '.$d['status'], 'entity_type' => 'support_tickets', 'entity_id' => $id, 'created_at' => now()]);
            Communications::notifyPermission($t->company_id, 'support.read', 'Statut du support', 'Le ticket #'.$id.' a changé de statut.', 'support');
        });

        return response()->json(['ok' => true]);
    }
}
