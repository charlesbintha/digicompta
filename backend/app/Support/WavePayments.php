<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WavePayments
{
    public static function enabled(): bool
    {
        return (bool) config('services.wave.enabled') && filled(config('services.wave.key')) && filled(config('services.wave.webhook_secret')) && str_starts_with((string) config('app.url'), 'https://');
    }

    public static function request(string $method, string $path, ?array $data = null): array
    {
        abort_unless(self::enabled(), 503, 'Le paiement Wave sera disponible après configuration de la plateforme.');
        $body = $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR);
        $client = Http::withToken(config('services.wave.key'))->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting();
        if ($secret = config('services.wave.signing_secret')) {
            $timestamp = (string) now()->timestamp;
            $client = $client->withHeaders(['Wave-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.$body, $secret)]);
        }
        try {
            $response = $client->send($method, 'https://api.wave.com/v1/checkout/sessions'.$path, $data === null ? [] : ['body' => $body, 'headers' => ['Content-Type' => 'application/json']]);
        } catch (ConnectionException) {
            abort(503, 'Wave est momentanément injoignable. Vérifiez le paiement avant toute nouvelle tentative.');
        }
        abort_unless($response->successful() && is_array($response->json()), 503, 'Impossible de vérifier le paiement auprès de Wave. Réessayez plus tard.');

        return $response->json();
    }

    public static function verifySignature(string $header, string $body): bool
    {
        $secret = config('services.wave.webhook_secret');
        if (! $secret || ! config('services.wave.enabled')) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || (int) $timestamp < now()->timestamp - 300 || (int) $timestamp > now()->timestamp + 30) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.$body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function apply(int $orderId, array $session): object
    {
        return DB::transaction(function () use ($orderId, $session) {
            $initial = DB::table('subscription_orders')->find($orderId);
            abort_unless($initial, 404);
            $company = DB::table('companies')->where('id', $initial->company_id)->lockForUpdate()->first();
            $order = DB::table('subscription_orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_unless($order->wave_reference && ($session['client_reference'] ?? null) === $order->wave_reference && ($session['currency'] ?? null) === 'XOF' && (string) ($session['amount'] ?? '') === (string) $order->total && preg_match('/^cos-[A-Za-z0-9]+$/', $session['id'] ?? '') && (! $order->wave_session_id || $order->wave_session_id === $session['id']), 422, 'La confirmation Wave ne correspond pas à la demande.');
            if ($order->status === 'paid') {
                return $order;
            }
            abort_unless($order->status === 'pending', 409, 'Cette demande est déjà clôturée.');
            $url = $session['wave_launch_url'] ?? null;
            abort_if($url !== null && ! preg_match('~^https://pay\.wave\.com/c/cos-[A-Za-z0-9]+$~', $url), 422, 'Lien de paiement Wave invalide.');
            $updates = ['wave_session_id' => $session['id'], 'updated_at' => now()];
            if ($url) {
                $updates['wave_launch_url'] = $url;
            }
            if (($session['payment_status'] ?? null) === 'succeeded' && ($session['checkout_status'] ?? null) === 'complete') {
                abort_unless(is_string($session['transaction_id'] ?? null) && strlen($session['transaction_id']) > 0 && strlen($session['transaction_id']) <= 200, 422, 'Référence de transaction Wave absente.');
                $plan = DB::table('plans')->find($order->plan_id);
                $base = $company->plan === $plan->name && $company->subscription_ends_at && now()->lt($company->subscription_ends_at) ? Carbon::parse($company->subscription_ends_at) : now();
                DB::table('companies')->where('id', $company->id)->update(['plan' => $plan->name, 'subscription_status' => $company->subscription_status === 'suspended' ? 'suspended' : 'active', 'subscription_ends_at' => $base->copy()->addDays(30), 'updated_at' => now()]);
                $updates += ['status' => 'paid', 'wave_state' => 'paid', 'wave_transaction_id' => $session['transaction_id'], 'payment_reference' => 'Wave '.$session['transaction_id'], 'paid_at' => now()];
                DB::table('audit_logs')->insert(['company_id' => $company->id, 'user_id' => $order->wave_requested_by, 'action' => 'Abonnement réglé par Wave', 'entity_type' => 'subscription_orders', 'entity_id' => $orderId, 'created_at' => now()]);
            } elseif (($session['checkout_status'] ?? null) === 'expired') {
                $updates['wave_state'] = 'expired';
            } else {
                $updates['wave_state'] = 'open';
            }
            DB::table('subscription_orders')->where('id', $orderId)->update($updates);

            return DB::table('subscription_orders')->find($orderId);
        });
    }
}
