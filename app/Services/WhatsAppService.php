<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $apiVersion;
    protected $businessPhone;
    protected $apiToken;

    public function __construct()
    {
        $this->apiVersion = config('services.whatsapp.api_version');
        $this->businessPhone = config('services.whatsapp.business_phone');
        $this->apiToken = config('services.whatsapp.api_token');
    }

    public function sendMessage($to, $body, $messageId = null)
    {
        try {
            if (empty($body)) {
                Log::error('El cuerpo del mensaje no puede estar vacío');
                return;
            }

            $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->businessPhone}/messages";
            
            $data = [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'text' => ['body' => $body],
            ];

            // if ($messageId) {
            //     $data['context'] = ['message_id' => $messageId];
            // }

            $response = Http::withToken($this->apiToken)->post($url, $data);

            if ($response->successful()) {
                Log::info('Mensaje enviado con éxito');
            } else {
                Log::error('Error al enviar el mensaje: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Error al enviar el mensaje: ' . $e->getMessage());
        }
    }

    public function markAsRead($messageId)
    {
        try {
            if (empty($messageId)) {
                Log::error('El ID del mensaje no puede estar vacío');
                return;
            }

            $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->businessPhone}/messages";

            $response = Http::withToken($this->apiToken)->post($url, [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ]);

            if ($response->successful()) {
                Log::info('Mensaje marcado como leído');
            } else {
                Log::error('Error al marcar el mensaje como leído: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Error al marcar el mensaje como leído: ' . $e->getMessage());
        }
    }

    public function sendInteractiveButtons($to, $bodyText, $buttons)
    {
        try {
            if (empty($bodyText) || empty($buttons)) {
                Log::error('El texto del mensaje y los botones son requeridos');
                return;
            }

            $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->businessPhone}/messages";

            $response = Http::withToken($this->apiToken)->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $bodyText],
                    'action' => [
                        'buttons' => $buttons,
                    ],
                ],
            ]);

            if ($response->successful()) {
                Log::info('Mensaje interactivo enviado con éxito');
            } else {
                Log::error('Error al enviar el mensaje interactivo: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Error al enviar el mensaje interactivo: ' . $e->getMessage());
        }
    }

    /**
     * Send a WhatsApp List Message (dropdown menu with up to 10 sections, 10 rows each).
     */
    public function sendListMessage($to, string $bodyText, string $buttonLabel, array $sections)
    {
        try {
            if (empty($bodyText) || empty($sections)) {
                Log::error('List message requires body text and sections');
                return;
            }

            $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->businessPhone}/messages";

            $response = Http::withToken($this->apiToken)->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'list',
                    'body' => ['text' => $bodyText],
                    'action' => [
                        'button' => mb_substr($buttonLabel, 0, 20),
                        'sections' => $sections,
                    ],
                ],
            ]);

            if ($response->successful()) {
                Log::info('List message sent successfully');
            } else {
                Log::error('List message failed: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('List message exception: ' . $e->getMessage());
        }
    }

    /**
     * Send a product card with image header + reply buttons.
     * Simulates Single Product Message without needing Meta Commerce Catalog.
     */
    public function sendProductCard($to, string $productName, string $description, ?string $imageUrl, array $buttons)
    {
        try {
            if (empty($productName) || empty($buttons)) {
                Log::error('Product card requires name and buttons');
                return;
            }

            $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->businessPhone}/messages";

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $description],
                    'footer' => ['text' => 'Roma Store ✨'],
                    'action' => ['buttons' => $buttons],
                ],
            ];

            if ($imageUrl) {
                $payload['interactive']['header'] = [
                    'type' => 'image',
                    'image' => ['link' => $imageUrl],
                ];
            }

            $response = Http::withToken($this->apiToken)->post($url, $payload);

            if ($response->successful()) {
                Log::info('Product card sent successfully');
            } else {
                Log::error('Product card failed: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Product card exception: ' . $e->getMessage());
        }
    }

    public function sendMediaMessage($to, $type, $mediaUrl, $caption = null)
    {
        try {
            if (empty($mediaUrl) || empty($type)) {
                Log::error('El tipo de medio y la URL son requeridos');
                return;
            }

            $mediaObject = [];

            switch ($type) {
                case 'image':
                    $mediaObject['image'] = ['link' => $mediaUrl, 'caption' => $caption];
                    break;
                case 'audio':
                    $mediaObject['audio'] = ['link' => $mediaUrl];
                    break;
                case 'video':
                    $mediaObject['video'] = ['link' => $mediaUrl, 'caption' => $caption];
                    break;
                case 'document':
                    $mediaObject['document'] = ['link' => $mediaUrl, 'caption' => $caption, 'filename' => 'productos.pdf'];
                    break;
                default:
                    Log::error('Tipo de medio no soportado');
                    return;
            }

            $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->businessPhone}/messages";

            $data = array_merge([
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => $type,
            ], $mediaObject);

            $response = Http::withToken($this->apiToken)->post($url, $data);

            if ($response->successful()) {
                Log::info('Mensaje multimedia enviado con éxito');
            } else {
                Log::error('Error al enviar el mensaje multimedia: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Error al enviar el mensaje multimedia: ' . $e->getMessage());
        }
    }
}
