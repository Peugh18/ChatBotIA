<?php

namespace App\Events;

use App\Models\Client;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('crm-dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'client.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'client' => [
                'id'                  => $this->client->id,
                'name'                => $this->client->name,
                'phone'               => $this->client->phone,
                'status'              => $this->client->status,
                'priority'            => $this->client->priority,
                'last_interaction_at' => $this->client->last_interaction_at?->toDateTimeString(),
            ],
        ];
    }
}
