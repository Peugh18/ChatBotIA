<?php

namespace App\Observers;

use App\Jobs\SyncMessageToRomaApi;
use App\Models\Message;

class MessageObserver
{
    public function created(Message $message): void
    {
        if (! config('services.roma_api.enabled')) {
            return;
        }

        // Salientes: Laravel ya envió por Meta; no pasar por roma-api (evita doble envío y doble fila)
        if ($message->from_me) {
            return;
        }

        SyncMessageToRomaApi::dispatch($message);
    }
}
