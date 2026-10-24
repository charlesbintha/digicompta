<?php

namespace App\Jobs;

use App\Support\Communications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $messageId) {}

    public function handle(Communications $communications): void
    {
        $claimed = DB::table('message_outbox')->where('id', $this->messageId)->where('status', 'queued')->update(['status' => 'sending', 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        try {
            $message = DB::table('message_outbox')->find($this->messageId);
            $communications->deliver($message);
            DB::table('message_outbox')->where('id', $this->messageId)->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('message_outbox')->where('id', $this->messageId)->update(['status' => 'failed', 'error' => 'Livraison non confirmée. Vérifiez la configuration et le destinataire avant de réessayer.', 'updated_at' => now()]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        DB::table('message_outbox')->where('id', $this->messageId)->whereIn('status', ['queued', 'sending'])->update(['status' => 'failed', 'error' => 'Livraison interrompue. Vérifiez si le message a été reçu avant de réessayer.', 'updated_at' => now()]);
    }
}
