<?php

namespace App\Notifications;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class HumanRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Client $client;

    /**
     * Create a new notification instance.
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'client_id' => $this->client->id,
            'client_name' => $this->client->name ?? $this->client->phone,
            'message' => 'El cliente ha solicitado comunicarse con un asesor humano.',
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'client_id' => $this->client->id,
            'client_name' => $this->client->name ?? $this->client->phone,
            'message' => 'El cliente ha solicitado comunicarse con un asesor humano.',
        ]);
    }
}
