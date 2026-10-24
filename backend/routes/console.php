<?php

use App\Models\User;
use App\Support\Communications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Minishlink\WebPush\VAPID;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('digi:grant-admin {email}', function () {
    $user = User::where('email', $this->argument('email'))->first();
    if (! $user) {
        $this->error('Utilisateur introuvable. Créez d’abord son compte.');

        return 1;
    }
    $user->forceFill(['is_super_admin' => true])->save();
    $this->info('Accès super administrateur attribué.');

    return 0;
})->purpose('Attribuer explicitement le rôle super administrateur à un compte existant');

Artisan::command('digi:check-sla', function () {
    $count = 0;
    DB::table('support_tickets')->whereNotIn('status', ['resolved', 'closed'])->whereNull('sla_alerted_at')->orderBy('id')->chunkById(100, function ($tickets) use (&$count) {
        foreach ($tickets as $ticket) {
            if ((! $ticket->responded_at && now()->gt($ticket->response_due_at)) || now()->gt($ticket->resolution_due_at)) {
                DB::transaction(function () use ($ticket, &$count) {
                    $claimed = DB::table('support_tickets')->where('id', $ticket->id)->whereNull('sla_alerted_at')->update(['sla_alerted_at' => now()]);
                    if ($claimed) {
                        Communications::notifyPermission($ticket->company_id, 'support.read', 'Délai de support dépassé', 'Le ticket #'.$ticket->id.' a dépassé un délai contractuel.', 'support');
                        $count++;
                    }
                });
            }
        }
    });
    $this->info($count.' alerte(s) SLA créée(s).');
})->purpose('Signaler une seule fois les tickets ayant dépassé leur SLA');
Schedule::command('digi:check-sla')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('digi:vapid-init {--subject=}', function () {
    $subject = $this->option('subject');
    if (! $subject || (! str_starts_with($subject, 'mailto:') && ! str_starts_with($subject, 'https://'))) {
        $this->error('Fournissez --subject=mailto:admin@votre-domaine.sn ou une URL HTTPS de contact.');

        return 1;
    }
    $path = base_path('.env');
    $content = file_get_contents($path);
    if (preg_match('/^VAPID_PRIVATE_KEY=.+$/m', $content)) {
        $this->error('Une clé existe déjà. Elle est conservée pour ne pas invalider les abonnements push.');

        return 1;
    }
    $keys = VAPID::createVapidKeys();
    foreach (['VAPID_SUBJECT' => $subject, 'VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']] as $key => $value) {
        if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, '"')) {
            $this->error('Valeur de configuration invalide.');

            return 1;
        }
        $line = $key.'="'.$value.'"';
        $content = preg_match('/^'.$key.'=.*$/m', $content) ? preg_replace('/^'.$key.'=.*$/m', $line, $content) : $content."\n".$line;
    }
    file_put_contents($path, $content."\n", LOCK_EX);
    $this->info('Clés VAPID enregistrées dans .env sans afficher le secret. Redémarrez le worker et reconstruisez le cache de configuration si nécessaire.');

    return 0;
})->purpose('Configurer les clés Web Push sans exposer la clé privée');
