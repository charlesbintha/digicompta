<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverMessage;
use App\Support\Communications;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommunicationController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'communications.read');
        $smtp = null;
        if (Tenant::allows($r, 'communications.manage')) {
            $smtp = Tenant::table($r, 'smtp_settings')->first();
            if ($smtp) {
                $smtp->has_password = ! empty($smtp->password);
                unset($smtp->password);
            }
        }

        return response()->json(['smtp' => $smtp, 'outbox' => Tenant::table($r, 'message_outbox')->where('channel', 'mail')->select('id', 'recipient', 'subject', 'status', 'error', 'sent_at', 'created_at')->orderByDesc('id')->limit(100)->get()]);
    }

    public function smtp(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'communications.manage');
        $d = $r->validate(['host' => 'required|string|max:253', 'port' => ['required', 'integer', Rule::in([465, 587])], 'encryption' => ['required', Rule::in(['ssl', 'tls'])], 'username' => 'required|string|max:255', 'password' => 'nullable|string|max:2000', 'from_email' => 'required|email|max:200', 'from_name' => 'required|string|max:150', 'enabled' => 'required|boolean']);
        abort_unless(($d['port'] == 465 && $d['encryption'] === 'ssl') || ($d['port'] == 587 && $d['encryption'] === 'tls'), 422, 'Utilisez TLS sur 587 ou SSL sur 465.');
        try {
            Communications::publicSmtpAddress($d['host']);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
        DB::transaction(function () use ($r, $d) {
            $company = Tenant::company($r)->id;
            DB::table('companies')->where('id', $company)->lockForUpdate()->first();
            $old = Tenant::table($r, 'smtp_settings')->first();
            abort_unless($old || ! empty($d['password']), 422, 'Mot de passe SMTP requis.');
            $d['password'] = ! empty($d['password']) ? Crypt::encryptString($d['password']) : $old->password;
            DB::table('smtp_settings')->updateOrInsert(['company_id' => $company], [...$d, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            Tenant::audit($r, 'Configuration SMTP modifiée', 'companies', $company);
        });

        return response()->json(['ok' => true]);
    }

    public function send(Request $r): JsonResponse
    {
        Tenant::authorize($r, 'communications.write');
        $d = $r->validate(['invoice_id' => 'nullable|integer', 'test' => 'sometimes|boolean']);
        abort_unless(Tenant::table($r, 'smtp_settings')->where('enabled', true)->exists(), 422, 'Configurez et activez le serveur SMTP.');
        $company = Tenant::company($r);
        if ($r->boolean('test')) {
            $recipient = $r->user()->email;
            $subject = 'Test SMTP Digi-Compta';
            $body = 'La configuration SMTP de '.$company->name.' permet l’envoi de ce message de test.';
        } else {
            $invoice = Tenant::table($r, 'invoices')->find($d['invoice_id'] ?? 0);
            abort_unless($invoice && in_array($invoice->status, ['issued', 'paid'], true), 422, 'Sélectionnez une facture validée.');
            $customer = json_decode($invoice->customer_snapshot);
            $recipient = $customer->email ?? null;
            abort_unless(filter_var($recipient, FILTER_VALIDATE_EMAIL), 422, 'La facture ne contient pas d’adresse email client valide.');
            $seller = json_decode($invoice->seller_snapshot);
            $subject = 'Facture '.$invoice->number.' — '.$seller->name;
            $body = $seller->name."\n".($seller->address ?? '')."\nNINEA : ".($seller->ninea ?? '')."\nRCCM : ".($seller->rccm ?? '')."\n\nFacture ".$invoice->number.' du '.$invoice->issue_date."\nClient : ".$customer->name."\n";
            foreach (DB::table('invoice_items')->where('invoice_id', $invoice->id)->get() as $item) {
                $body .= $item->description.' — '.$item->quantity.' × '.$item->unit_price.' = '.$item->total." XOF\n";
            }$paid = DB::table('payments')->where('invoice_id', $invoice->id)->sum('amount');
            $body .= "\nHT : ".$invoice->subtotal.' XOF / Taxe : '.$invoice->tax_amount.' XOF / Total : '.$invoice->total.' XOF / Avoirs : '.$invoice->credited.' XOF / Solde : '.($invoice->total - $paid - $invoice->credited)." XOF\nÉchéance : ".$invoice->due_date."\n".$invoice->notes;
        }
        $id = DB::transaction(function () use ($r, $company, $recipient, $subject, $body) {
            $id = Communications::queue($company->id, $r->user()->id, 'mail', $recipient, $subject, $body);
            Tenant::audit($r, 'Email mis en file', 'message_outbox', $id);

            return $id;
        });

        return response()->json(['id' => $id], 202);
    }

    public function retry(Request $r, int $id): JsonResponse
    {
        Tenant::authorize($r, 'communications.write');
        DB::transaction(function () use ($r, $id) {
            $m = Tenant::table($r, 'message_outbox')->where('channel', 'mail')->where('id', $id)->lockForUpdate()->first();
            abort_unless($m, 404);
            abort_unless($m->status === 'failed', 422, 'Seuls les envois en échec peuvent être relancés.');
            DB::table('message_outbox')->where('id', $id)->update(['status' => 'queued', 'user_id' => $r->user()->id, 'error' => null, 'updated_at' => now()]);
            DeliverMessage::dispatch($id)->afterCommit();
            Tenant::audit($r, 'Email relancé', 'message_outbox', $id);
        });

        return response()->json(['ok' => true], 202);
    }

    public function notifications(Request $r): JsonResponse
    {
        return response()->json(['notifications' => Tenant::table($r, 'user_notifications')->where('user_id', $r->user()->id)->orderByDesc('id')->limit(100)->get(), 'push_public_key' => config('digi.vapid.public_key'), 'push_enabled' => Tenant::table($r, 'push_subscriptions')->where('user_id', $r->user()->id)->exists()]);
    }

    public function read(Request $r): JsonResponse
    {
        Tenant::table($r, 'user_notifications')->where('user_id', $r->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function push(Request $r): JsonResponse
    {
        abort_unless(config('digi.vapid.public_key') && config('digi.vapid.private_key'), 422, 'Le serveur push doit être configuré par la plateforme.');
        $d = $r->validate(['endpoint' => 'required|url|max:2000', 'keys' => 'required|array:p256dh,auth', 'keys.p256dh' => 'required|string|regex:/^[A-Za-z0-9_-]{87}$/', 'keys.auth' => 'required|string|regex:/^[A-Za-z0-9_-]{22}$/']);
        abort_unless(Communications::pushEndpoint($d['endpoint']), 422, 'Ce fournisseur push n’est pas autorisé.');
        $company = Tenant::company($r)->id;
        DB::table('push_subscriptions')->updateOrInsert(['company_id' => $company, 'user_id' => $r->user()->id, 'fingerprint' => hash('sha256', $d['endpoint'])], ['payload' => Crypt::encryptString(json_encode($d)), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function removePush(Request $r): JsonResponse
    {
        Tenant::table($r, 'push_subscriptions')->where('user_id', $r->user()->id)->delete();

        return response()->json(['ok' => true]);
    }
}
