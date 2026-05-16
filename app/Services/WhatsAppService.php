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
