<?php

namespace App\Services;

use App\Models\Pedido;
use App\Models\UserState;
use Illuminate\Support\Facades\Log;

class MessageHandler
{
    protected $whatsAppService;
    protected $products;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
        $this->products = [
            "M88" => ["name" => "Audifonos M88 PLUS", "price" => 37.00],
            "M41" => ["name" => "Audifono Bluetooth TWS Power Bank M41", "price" => 35.99],
            "M25" => ["name" => "Audífonos TWS M25 Bluetooth Auriculares Gamer Inalámbricos", "price" => 39.99],
            "M28" => ["name" => "Audífonos Bluetooth M28 Gamer", "price" => 25.99],
            "MAS" => ["name" => "Masajeador Facial con Microcorriente", "price" => 29.99],
            "CARGA4" => ["name" => "Cable de Carga 4 en 1", "price" => 17.99],
            "ENCEN" => ["name" => "Encendedor Eléctrico", "price" => 14.90]
        ];
    }

    public function handleIncomingMessage($message, $senderInfo)
    {
        $from = $message['from'] ?? null;
        if (!$from) return;

        if (isset($message['type']) && $message['type'] === 'text') {
            $incomingMessage = strtolower(trim($message['text']['body']));

            if ($this->isGreeting($incomingMessage)) {
                $this->sendWelcomeMessage($from, $message['id'], $senderInfo);
                $this->sendWelcomeMenu($from);
            } elseif ($incomingMessage === 'media') {
                $this->sendMedia($from, 'document', 'https://drive.google.com/uc?export=download&id=1SZ8gDr7dWofGlt-1f6osr_MZmbnRMhiP', '¡Aquí están los productos que tenemos en este año 2025!');
            } else {
                $userState = UserState::where('phone', $from)->first();
                if ($userState) {
                    $this->handleAppointmentFlow($from, $incomingMessage, $userState);
                } else {
                    $response = "Echo: " . $message['text']['body'];
                    $this->whatsAppService->sendMessage($from, $response, $message['id']);
                }
            }
            $this->whatsAppService->markAsRead($message['id']);
        } elseif (isset($message['type']) && $message['type'] === 'interactive') {
            $option = strtolower(trim($message['interactive']['button_reply']['title'] ?? ''));
            $this->handleMenuOption($from, $option);
            $this->whatsAppService->markAsRead($message['id']);
        }
    }

    protected function isGreeting($message)
    {
        $greetings = ["hola", "hello", "hi", "buenas tardes", "buenos días", "buenas noches", "¡hola, quiero más información sobre sus productos!"];
        return in_array($message, $greetings);
    }

    protected function getSenderName($senderInfo)
    {
        return $senderInfo['profile']['name'] ?? $senderInfo['wa_id'] ?? 'Usuario';
    }

    protected function sendWelcomeMessage($to, $messageId, $senderInfo)
    {
        $name = $this->getSenderName($senderInfo);
        $welcomeMessage = "Hola {$name}, Bienvenido a UP STORE, tu tienda de accesorios y más. ¿En qué puedo ayudarte hoy?";
        $this->whatsAppService->sendMessage($to, $welcomeMessage, $messageId);
    }

    protected function sendWelcomeMenu($to)
    {
        $menuMessage = "Elige una Opción";
        $buttons = [
            ['type' => 'reply', 'reply' => ['id' => 'option_1', 'title' => 'Ver Productos']],
            ['type' => 'reply', 'reply' => ['id' => 'option_2', 'title' => 'Promociones']],
            ['type' => 'reply', 'reply' => ['id' => 'option_3', 'title' => 'Hablar con un Asesor']]
        ];
        $this->whatsAppService->sendInteractiveButtons($to, $menuMessage, $buttons);
    }

    protected function sendAdditionalOptionsMenu($to)
    {
        $menuMessage = "¿Qué te gustaría hacer ahora?";
        $buttons = [
            ['type' => 'reply', 'reply' => ['id' => 'option_4', 'title' => 'Comprar Producto']],
            ['type' => 'reply', 'reply' => ['id' => 'option_5', 'title' => 'Contactar Asesor']]
        ];
        $this->whatsAppService->sendInteractiveButtons($to, $menuMessage, $buttons);
    }

    protected function handleMenuOption($to, $option)
    {
        $response = null;

        switch ($option) {
            case 'ver productos':
                $this->sendMedia($to, 'document', 'https://drive.google.com/uc?export=download&id=1SZ8gDr7dWofGlt-1f6osr_MZmbnRMhiP', '¡Aquí están los productos que tenemos en este año 2025!');
                $this->sendAdditionalOptionsMenu($to);
                break;

            case 'promociones':
                $response = "Nuestras promociones están agotadas por el momento. ¡Pero no te preocupes! Estamos trabajando en nuevas promociones para ti. 😊";
                break;

            case 'hablar con un asesor':
                $response = 'Un asesor se pondrá en contacto contigo en breve.';
                break;

            case 'comprar producto':
                UserState::updateOrCreate(
                    ['phone' => $to],
                    ['state' => ['step' => 'name']]
                );
                $response = "Indícanos tu nombre para continuar con la compra.";
                break;

            case 'contactar asesor':
                $response = "Gracias por tu interés. Un asesor se pondrá en contacto contigo lo antes posible. Si deseas acelerar el proceso, por favor proporciona más detalles sobre tu consulta. 😊";
                break;

            default:
                $response = "Lo siento, no entendí tu selección. Por favor, elige una de las opciones del menú.";
        }

        if ($response) {
            $this->whatsAppService->sendMessage($to, $response);
        }
    }

    protected function handleAppointmentFlow($to, $message, $userState)
    {
        $state = $userState->state;
        $response = null;

        switch ($state['step'] ?? 'name') {
            case 'name':
                $state['name'] = $message;
                $state['step'] = 'celular';
                $response = 'Por favor, indícame tu número de celular para continuar con la programación del envío.';
                break;

            case 'celular':
                $state['celular'] = $message;
                $state['step'] = 'direccion';
                $response = 'Perfecto. Ahora, por favor indícame tu dirección de envío.';
                break;

            case 'direccion':
                $state['direccion'] = $message;
                $state['step'] = 'producto';
                $response = "¡Gracias! Ahora, por favor indícame el código del producto que deseas comprar. Los códigos son:\n
                - M88: Audifonos M88 PLUS (S/ 37.00)
                - M41: Audifono Bluetooth TWS Power Bank M41 (S/ 35.99)
                - M25: Audífonos TWS M25 Bluetooth Auriculares Gamer Inalámbricos (S/ 39.99)
                - M28: Audífonos Bluetooth M28 Gamer (S/ 25.99)
                - MAS: Masajeador Facial con Microcorriente (S/ 29.99)
                - CARGA4: Cable de Carga 4 en 1 (S/ 17.99)
                - ENCEN: Encendedor Eléctrico (S/ 14.90)";
                break;

            case 'producto':
                $code = strtoupper($message);
                if (isset($this->products[$code])) {
                    $state['product'] = $this->products[$code];
                    $state['step'] = 'cantidad';
                    $response = "¡Perfecto! Has seleccionado: {$state['product']['name']}. Por favor, indícame la cantidad que deseas comprar.";
                } else {
                    $response = 'Lo siento, no reconocí el código del producto. Por favor, ingresa un código válido.';
                }
                break;

            case 'cantidad':
                $cantidad = (int)$message;
                if ($cantidad <= 0) {
                    $response = 'Por favor, ingresa una cantidad válida.';
                } else {
                    $state['cantidad'] = $cantidad;
                    $state['total'] = number_format($state['product']['price'] * $cantidad, 2, '.', '');
                    $state['step'] = 'confirmar';
                    $response = "¡Gracias! Hemos recibido tu información:\n
                    - Nombre: {$state['name']}
                    - Celular: {$state['celular']}
                    - Dirección: {$state['direccion']}
                    - Producto: {$state['product']['name']}
                    - Cantidad: {$state['cantidad']}
                    - Total a pagar: S/ {$state['total']}
                    ¿Es correcto? Responde con \"Sí\" para confirmar o \"No\" para reiniciar.";
                }
                break;

            case 'confirmar':
                if (strtolower($message) === 'si') {
                    $this->completeAppointment($to, $state);
                    $this->sendPaymentMethod($to);
                    $userState->delete();
                    return;
                } elseif (strtolower($message) === 'no') {
                    $response = 'Entiendo. Vamos a reiniciar el proceso. Por favor, indícame tu nombre.';
                    $state = ['step' => 'name'];
                } else {
                    $response = 'Por favor responde con "Si" o "No".';
                }
                break;

            default:
                $response = 'Lo siento, no entendí tu mensaje. Por favor, indícame tu nombre para continuar.';
                $state = ['step' => 'name'];
        }

        $userState->update(['state' => $state]);

        if ($response) {
            $this->whatsAppService->sendMessage($to, $response);
        }
    }

    protected function completeAppointment($to, $state)
    {
        Pedido::create([
            'phone' => $to,
            'name' => $state['name'],
            'cellphone' => $state['celular'],
            'address' => $state['direccion'],
            'product_name' => $state['product']['name'],
            'product_price' => $state['product']['price'],
            'quantity' => $state['cantidad'],
            'total' => $state['total'],
        ]);

        $completionMessage = "¡Gracias por confirmar tu pedido, {$state['name']}! Procederemos a procesar tu pedido y te enviaremos una confirmación por este medio pronto. 😊";
        $this->whatsAppService->sendMessage($to, $completionMessage);
    }

    protected function sendPaymentMethod($to)
    {
        $paymentMessage = "¡Gracias por elegir ShopLife! Para realizar el pago por Yape, utiliza los siguientes datos:

👉 Número de Yape: *959166911*  
👉 Nombre: *José Urcia*

Una vez realizado el pago, envíanos una captura para confirmar tu pedido. ¡Agradecemos tu preferencia! 😊";
        $this->whatsAppService->sendMessage($to, $paymentMessage);
    }

    protected function sendMedia($to, $type, $mediaUrl, $caption)
    {
        $this->whatsAppService->sendMediaMessage($to, $type, $mediaUrl, $caption);
    }
}
