<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Integration\RomaApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMessageToRomaApi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Message $message) {}

    public function handle(RomaApiService $romaApi): void
    {
        $romaApi->syncMessage($this->message);
    }
}
