<?php

namespace App\Http\Controllers;

use App\Support\Tenant;
use App\Support\WavePayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WaveController extends Controller
{
    public function checkout(Request $request, int $id): JsonResponse
    {
        Tenant::authorize($request, 'subscription.write');
        abort_unless(WavePayments::enabled(), 503, 'Wave est en attente de configuration.');
        [$order, $create] = DB::transaction(function () use ($request, $id) {
            DB::table('companies')->where('id', Tenant::company($request)->id)->lockForUpdate()->first();
            $order = Tenant::table($request, 'subscription_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            abort_unless($order->status === 'pending' && $order->total > 0, 422, 'Cette demande ne nécessite pas de paiement Wave.');
            $create = ! $order->wave_reference;
            if ($create) {
                $order->wave_reference = (string) Str::uuid();
                DB::table('subscription_orders')->where('id', $id)->update(['wave_requested_by' => $request->user()->id, 'wave_reference' => $order->wave_reference, 'wave_state' => 'creating', 'updated_at' => now()]);
                Tenant::audit($request, 'Paiement Wave demandé', 'subscription_orders', $id);
            }

            return [$order, $create];
        });
        if (! $create) {
            return $this->refresh($request, $id);
        }
        $returnUrl = rtrim(config('app.url'), '/').'/?wave_order='.$id;
        $session = WavePayments::request('POST', '', ['amount' => (string) $order->total, 'currency' => 'XOF', 'client_reference' => $order->wave_reference, 'success_url' => $returnUrl, 'error_url' => $returnUrl.'&wave_error=1']);

        return response()->json($this->result(WavePayments::apply($id, $session)));
    }

    public function refresh(Request $request, int $id): JsonResponse
    {
        Tenant::authorize($request, 'subscription.write');
        $order = Tenant::table($request, 'subscription_orders')->where('id', $id)->first();
        abort_unless($order, 404);
        abort_unless($order->wave_reference, 422, 'Aucun paiement Wave engagé.');
        if ($order->status === 'paid') {
            return response()->json($this->result($order));
        }
        if ($order->wave_session_id) {
            $session = WavePayments::request('GET', '/'.rawurlencode($order->wave_session_id));
        } else {
            $search = WavePayments::request('GET', '/search?client_reference='.rawurlencode($order->wave_reference));
            abort_unless(count($search['result'] ?? []) === 1, 409, 'Paiement en cours de vérification. Contactez le support si cette situation persiste ; ne payez pas une seconde fois.');
            $session = $search['result'][0];
        }

        return response()->json($this->result(WavePayments::apply($id, $session)));
    }

    public function webhook(Request $request): JsonResponse
    {
        abort_unless(WavePayments::verifySignature((string) $request->header('Wave-Signature'), $request->getContent()), 401);
        $event = $request->validate(['type' => 'required|string', 'data' => 'required|array']);
        if ($event['type'] !== 'checkout.session.completed') {
            return response()->json(['ok' => true]);
        }
        $reference = $event['data']['client_reference'] ?? null;
        $order = is_string($reference) ? DB::table('subscription_orders')->where('wave_reference', $reference)->first() : null;
        if ($order) {
            WavePayments::apply($order->id, $event['data']);
        }

        return response()->json(['ok' => true]);
    }

    private function result(object $order): array
    {
        return ['status' => $order->status, 'wave_state' => $order->wave_state, 'url' => $order->status === 'pending' && $order->wave_state === 'open' ? $order->wave_launch_url : null];
    }
}
