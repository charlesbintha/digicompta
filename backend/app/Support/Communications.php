<?php

namespace App\Support;

use App\Jobs\DeliverMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class Communications
{
    public static function publicSmtpAddress(string $host): string
    {
        if (! preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $host) || ! str_contains($host, '.')) {
            throw new \InvalidArgumentException('Hôte SMTP public requis.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            throw new \InvalidArgumentException('Hôte SMTP introuvable.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
                throw new \InvalidArgumentException('Un serveur SMTP public est requis.');
            }
        }

        return $ips[0];
    }

    public static function pushEndpoint(string $endpoint): bool
    {
        $u = parse_url($endpoint);
        if (! $u || ($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass']) || isset($u['fragment']) || isset($u['port']) && $u['port'] !== 443) {
            return false;
        }$host = strtolower($u['host'] ?? '');

        return $host === 'fcm.googleapis.com' || str_ends_with($host, '.push.services.mozilla.com') || str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.notify.windows.com');
    }

    public static function queue(int $company, int $user, string $channel, string $recipient, string $subject, string $body): int
    {
        $id = DB::table('message_outbox')->insertGetId(['company_id' => $company, 'user_id' => $user, 'channel' => $channel, 'recipient' => $recipient, 'subject' => $subject, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        DeliverMessage::dispatch($id)->afterCommit();

        return $id;
    }

    public static function notifyPermission(int $company, string $permission, string $title, string $body, string $target): void
    {
        foreach (DB::table('memberships')->where('company_id', $company)->where('is_active', true)->get() as $m) {
            if (! in_array($permission, Tenant::permissions($m->role, $company), true)) {
                continue;
            }DB::table('user_notifications')->insert(['company_id' => $company, 'user_id' => $m->user_id, 'title' => $title, 'body' => $body, 'target' => $target, 'created_at' => now()]);
            if (config('digi.vapid.private_key')) {
                foreach (DB::table('push_subscriptions')->where('company_id', $company)->where('user_id', $m->user_id)->get() as $s) {
                    self::queue($company, $m->user_id, 'push', (string) $s->id, 'Digi-Compta', 'Une nouvelle activité est disponible dans votre espace.');
                }
            }
        }
    }

    public function deliver(object $message): void
    {
        $member = DB::table('memberships')->where('company_id', $message->company_id)->where('user_id', $message->user_id)->where('is_active', true)->first();
        if (! $member) {
            throw new \RuntimeException('Accès désactivé.');
        }
        if ($message->channel === 'mail') {
            if (! Entitlements::active(DB::table('companies')->find($member->company_id)) || ! in_array('communications.write', Entitlements::filter(DB::table('companies')->find($member->company_id), Tenant::permissions($member->role, $member->company_id)), true)) {
                throw new \RuntimeException('Permission désactivée.');
            }
            $s = DB::table('smtp_settings')->where('company_id', $message->company_id)->where('enabled', true)->first();
            if (! $s) {
                throw new \RuntimeException('SMTP désactivé.');
            }
            $ip = self::publicSmtpAddress($s->host);
            $transport = new EsmtpTransport($ip, $s->port, $s->encryption === 'ssl');
            $transport->setRequireTls(true)->setUsername($s->username)->setPassword(Crypt::decryptString($s->password));
            $transport->getStream()->setTimeout(20);
            $transport->getStream()->setStreamOptions(['ssl' => ['peer_name' => $s->host, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
            (new Mailer($transport))->send((new Email)->from(new Address($s->from_email, $s->from_name))->to($message->recipient)->subject($message->subject)->text($message->body));
        } else {
            $s = DB::table('push_subscriptions')->where('company_id', $message->company_id)->where('user_id', $message->user_id)->find((int) $message->recipient);
            if (! $s) {
                throw new \RuntimeException('Abonnement push supprimé.');
            }$payload = json_decode(Crypt::decryptString($s->payload), true);
            if (! self::pushEndpoint($payload['endpoint'])) {
                throw new \RuntimeException('Endpoint interdit.');
            }$push = new WebPush(['VAPID' => ['subject' => config('digi.vapid.subject'), 'publicKey' => config('digi.vapid.public_key'), 'privateKey' => config('digi.vapid.private_key')]], [], 20);
            $result = $push->sendOneNotification(Subscription::create($payload), json_encode(['title' => $message->subject, 'body' => $message->body]));
            if (! $result->isSuccess()) {
                if ($result->isSubscriptionExpired()) {
                    DB::table('push_subscriptions')->where('id', $s->id)->delete();
                }throw new \RuntimeException('Push non livré.');
            }
        }
    }
}
