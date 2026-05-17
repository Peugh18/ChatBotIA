<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;
    public string $phone;
    public string $clientName;
    public string $status;

    public function __construct(Message $message)
    {
        $message->load('client');
        $this->message    = $message;
        $this->phone      = $message->client->phone ?? '';
        $this->clientName = $message->client->name ?? $this->phone;
        $this->status     = $message->client->status ?? 'NUEVO';
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('client.' . $this->message->client_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id'         => $this->message->id,
                'client_id'  => $this->message->client_id,
                'from_me'    => $this->message->from_me,
                'body'       => $this->message->body,
                'type'       => $this->message->type,
                'created_at' => $this->message->created_at?->toDateTimeString(),
            ],
            'client' => [
                'id'     => $this->message->client_id,
                'name'   => $this->clientName,
                'phone'  => $this->phone,
                'status' => $this->status,
            ],
        ];
    }
}
