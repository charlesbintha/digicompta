<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WavePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $role = 'owner'): int
    {
        config(['services.wave.enabled' => true, 'services.wave.key' => 'test-key', 'services.wave.webhook_secret' => 'test-secret', 'app.url' => 'https://digi.example.test']);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $company = DB::table('companies')->insertGetId(['name' => 'Teranga', 'plan' => 'Business', 'subscription_status' => 'trial', 'trial_ends_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['company_id' => $company, 'user_id' => $user->id, 'role' => $role, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->withSession(['company_id' => $company]);
        $plan = DB::table('plans')->where('name', 'Business')->first();

        return DB::table('subscription_orders')->insertGetId(['company_id' => $company, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'subtotal' => 10000, 'discount' => 1000, 'total' => 9000, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function waveSession(int $id, array $changes = []): array
    {
        return array_replace(['id' => 'cos-test123', 'amount' => '9000', 'currency' => 'XOF', 'client_reference' => DB::table('subscription_orders')->find($id)->wave_reference, 'checkout_status' => 'open', 'payment_status' => 'processing', 'wave_launch_url' => 'https://pay.wave.com/c/cos-test123'], $changes);
    }

    private function checkout(int $id): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.wave.com/*' => fn () => Http::response($this->waveSession($id))]);
        $this->postJson("/api/v1/subscription/orders/$id/wave")->assertOk()->assertJsonPath('url', 'https://pay.wave.com/c/cos-test123');
    }

    private function webhook(array $session, int $offset = 0, string $secret = 'test-secret'): TestResponse
    {
        $body = json_encode(['id' => 'EV_test', 'type' => 'checkout.session.completed', 'data' => $session]);
        $time = (string) (now()->timestamp + $offset);
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.$body, $secret);

        return $this->call('POST', '/api/v1/webhooks/wave', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_WAVE_SIGNATURE' => $signature], $body);
    }

    public function test_checkout_uses_server_price_and_reuses_session(): void
    {
        $id = $this->order();
        $this->checkout($id);
        $this->postJson("/api/v1/subscription/orders/$id/wave", ['amount' => 1])->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['amount'] === '9000' && $r['currency'] === 'XOF' && $r['success_url'] === "https://digi.example.test/?wave_order=$id");
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_signed_webhook_activates_exactly_once_and_creates_no_notification(): void
    {
        $id = $this->order();
        $this->checkout($id);
        $session = $this->waveSession($id, ['checkout_status' => 'complete', 'payment_status' => 'succeeded', 'transaction_id' => 'TX123']);
        $this->webhook($session)->assertOk();
        $end = DB::table('companies')->first()->subscription_ends_at;
        $this->webhook($session)->assertOk();
        $this->assertSame($end, DB::table('companies')->first()->subscription_ends_at);
        $this->assertDatabaseHas('subscription_orders', ['id' => $id, 'status' => 'paid', 'payment_reference' => 'Wave TX123']);
        $this->assertDatabaseHas('companies', ['subscription_status' => 'active']);
        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'Abonnement réglé par Wave')->count());
    }

    public function test_forged_stale_future_or_incorrect_payment_is_rejected(): void
    {
        $id = $this->order();
        $this->checkout($id);
        $s = $this->waveSession($id, ['checkout_status' => 'complete', 'payment_status' => 'succeeded', 'transaction_id' => 'TX1']);
        $this->webhook($s, 0, 'wrong')->assertUnauthorized();
        $this->webhook($s, -301)->assertUnauthorized();
        $this->webhook($s, 31)->assertUnauthorized();
        foreach ([['amount' => '1'], ['currency' => 'EUR'], ['id' => 'cos-wrong']] as $bad) {
            $this->webhook(array_replace($s, $bad))->assertUnprocessable();
        }
        $this->assertDatabaseHas('subscription_orders', ['id' => $id, 'status' => 'pending']);
    }

    public function test_unknown_network_outcome_is_recovered_without_second_creation(): void
    {
        $id = $this->order();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::failedConnection()]);
        $this->postJson("/api/v1/subscription/orders/$id/wave")->assertStatus(503);
        $this->assertNotNull(DB::table('subscription_orders')->find($id)->wave_reference);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['result' => [$this->waveSession($id)]])]);
        $this->postJson("/api/v1/subscription/orders/$id/wave")->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/search?client_reference='));
    }

    public function test_pending_wave_blocks_manual_confirmation_and_cancellation(): void
    {
        $id = $this->order();
        $this->checkout($id);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        foreach (['confirm', 'cancel'] as $action) {
            $this->postJson("/api/v1/admin/orders/$id/decision", ['action' => $action, 'payment_reference' => 'MANUAL'])->assertConflict();
        }
    }

    public function test_verified_expiration_allows_manual_cancellation(): void
    {
        $id = $this->order();
        $this->checkout($id);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->waveSession($id, ['checkout_status' => 'expired', 'payment_status' => 'cancelled']))]);
        $this->postJson("/api/v1/subscription/orders/$id/wave/refresh")->assertOk()->assertJsonPath('url', null);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $this->postJson("/api/v1/admin/orders/$id/decision", ['action' => 'cancel'])->assertOk();
    }

    public function test_refresh_can_settle_missing_webhook_and_preserves_suspension(): void
    {
        $id = $this->order();
        $this->checkout($id);
        DB::table('companies')->update(['subscription_status' => 'suspended']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->waveSession($id, ['checkout_status' => 'complete', 'payment_status' => 'succeeded', 'transaction_id' => 'TX2']))]);
        $this->postJson("/api/v1/subscription/orders/$id/wave/refresh")->assertOk()->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('companies', ['subscription_status' => 'suspended']);
    }

    public function test_configuration_and_tenant_permissions_are_enforced(): void
    {
        $id = $this->order();
        config(['services.wave.enabled' => false]);
        $this->postJson("/api/v1/subscription/orders/$id/wave")->assertStatus(503);
        config(['services.wave.enabled' => true]);
        $other = $this->order();
        $this->postJson("/api/v1/subscription/orders/$id/wave")->assertNotFound();
        $this->postJson("/api/v1/subscription/orders/$id/wave/refresh")->assertNotFound();
        DB::table('memberships')->update(['role' => 'viewer']);
        $this->postJson("/api/v1/subscription/orders/$other/wave")->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_request_signing_uses_exact_body_and_untrusted_redirect_is_rejected(): void
    {
        $id = $this->order();
        config(['services.wave.signing_secret' => 'request-secret']);
        Http::fake(['*' => fn () => Http::response($this->waveSession($id, ['wave_launch_url' => 'https://attacker.test/pay']))]);
        $this->postJson("/api/v1/subscription/orders/$id/wave")->assertUnprocessable();
        Http::assertSent(function ($request) {
            $header = $request->header('Wave-Signature')[0];
            [$time, $signature] = explode(',', $header);

            return substr($signature, 3) === hash_hmac('sha256', substr($time, 2).$request->body(), 'request-secret');
        });
        $this->assertDatabaseHas('subscription_orders', ['id' => $id, 'status' => 'pending', 'wave_launch_url' => null]);
    }

    public function test_zero_total_and_unsigned_callbacks_never_activate_subscription(): void
    {
        $id = $this->order();
        DB::table('subscription_orders')->where('id', $id)->update(['total' => 0]);
        $this->postJson("/api/v1/subscription/orders/$id/wave")->assertUnprocessable();
        $this->postJson('/api/v1/webhooks/wave', ['type' => 'checkout.session.completed', 'data' => []])->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertDatabaseHas('companies', ['subscription_status' => 'trial']);
    }

    public function test_existing_active_subscription_is_extended_from_its_end(): void
    {
        $id = $this->order();
        $end = now()->addDays(12)->startOfSecond();
        DB::table('companies')->update(['subscription_status' => 'active', 'subscription_ends_at' => $end]);
        $this->checkout($id);
        $this->webhook($this->waveSession($id, ['checkout_status' => 'complete', 'payment_status' => 'succeeded', 'transaction_id' => 'TX3']))->assertOk();
        $this->assertEquals($end->copy()->addDays(30), Carbon::parse(DB::table('companies')->first()->subscription_ends_at));
    }
}
