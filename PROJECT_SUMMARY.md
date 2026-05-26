# Roma Store - WhatsApp CRM con IA

## Resumen General

Sistema CRM completo para gestionar conversaciones de WhatsApp con clientes, automatización con IA (Gemini), sincronización con roma-api (Next.js), y dashboard de ventas.

## Estructura del Proyecto

```
romaabc/
├── app/
│   ├── Http/Controllers/
│   │   ├── DashboardController.php       # CRM principal, chat, ventas
│   │   ├── WhatsAppController.php        # Webhook de Meta WhatsApp
│   │   ├── RomaMessageIngestController.php # Recibe mensajes de roma-api
│   │   ├── ClientBotController.php       # Control de bot por cliente
│   │   ├── CategoryController.php        # CRUD categorías
│   │   ├── ClientNoteController.php      # Notas internas de clientes
│   │   ├── DeliveryZoneController.php    # Zonas de entrega
│   │   ├── InventoryController.php       # Inventario productos
│   │   ├── QuickReplyController.php      # Respuestas rápidas
│   │   ├── TagController.php             # Etiquetas de clientes
│   │   ├── SettingController.php         # Configuración de negocio
│   │   └── Auth/*.php                    # Autenticación
│   ├── Models/
│   │   ├── Client.php                    # Cliente (WhatsApp, estado, lead_score)
│   │   ├── Message.php                   # Mensajes del chat
│   │   ├── Order.php                     # Pedidos de venta
│   │   ├── OrderItem.php                 # Items del pedido
│   │   ├── Product.php                   # Productos
│   │   ├── ProductVariant.php            # Variantes (color, talla, stock)
│   │   ├── Category.php                  # Categorías
│   │   ├── DeliveryZone.php              # Zonas de entrega
│   │   ├── Tag.php                       # Etiquetas
│   │   ├── QuickReply.php                # Respuestas rápidas
│   │   ├── ClientNote.php                # Notas internas
│   │   ├── User.php                      # Usuarios del sistema
│   │   └── Setting.php                   # Configuración general
│   ├── Services/
│   │   ├── Messaging/
│   │   │   ├── WhatsAppService.php       # Envío de mensajes WhatsApp (delegado a roma-api)
│   │   │   └── WhatsAppMessageService.php
│   │   ├── AI/
│   │   │   ├── GeminiService.php         # Integración Gemini 2.0 Flash
│   │   │   ├── AIPromptService.php       # Generación de prompts IA
│   │   │   └── IntentService.php         # Detección de intención
│   │   ├── Integration/
│   │   │   └── RomaApiService.php        # Sincronización con roma-api/Next.js
│   │   ├── Business/
│   │   │   ├── ClientService.php         # Gestión de clientes
│   │   │   ├── OrderService.php          # Gestión de pedidos
│   │   │   ├── PaymentService.php        # Verificación de pagos Yape
│   │   │   ├── ProductSearchService.php  # Búsqueda de productos
│   │   │   ├── VariantSelectionService.php
│   │   │   └── DeliveryService.php       # Envíos
│   │   ├── State/
│   │   │   └── ClientStateMachine.php    # Estados del cliente (NUEVO, INTERESADO, etc.)
│   │   └── Utility/
│   │       ├── ImageService.php
│   │       └── MessageHandler.php
│   ├── Jobs/
│   │   ├── SyncMessageToRomaApi.php      # Sincroniza mensajes a roma-api
│   │   ├── ProcessWhatsAppWebhook.php    # Procesa webhook de WhatsApp
│   │   ├── AbandonedCartFollowUpJob.php
│   │   ├── ConfirmationFollowUpJob.php
│   │   ├── FastFollowUpJob.php
│   │   ├── PaymentReminderJob.php
│   │   ├── PostSaleFollowUpJob.php
│   │   └── ShippingDataFollowUpJob.php
│   ├── Console/Commands/
│   │   ├── SyncRomaMessagesCommand.php   # Artisan: roma:sync-messages
│   │   └── ProcessStuckWhatsAppJobs.php
│   ├── Events/
│   │   ├── ClientStatusUpdated.php       # Transmitido por Pusher
│   │   └── MessageReceived.php           # Transmitido por Pusher
│   ├── Observers/
│   │   └── MessageObserver.php
│   └── Providers/AppServiceProvider.php
│
├── config/
│   ├── services.php                       # Config de WhatsApp, Gemini, roma-api
│   ├── app.php                            # App name, locale, debug
│   ├── database.php                       # MySQL, SQLite, PostgreSQL
│   ├── queue.php                          # Cola de jobs
│   ├── broadcasting.php                   # Configuración de Pusher (WebSocket)
│   └── ...
│
├── routes/
│   ├── web.php                            # Rutas Inertia (CRM, dashboard)
│   └── api.php                            # Webhook WhatsApp, roma-api ingest
│
├── resources/
│   └── js/
│       ├── pages/Dashboard.vue            # Vista principal CRM
│       ├── stores/
│       │   └── crm.ts                     # Estado global CRM (Pinia + Pusher)
│       ├── components/
│       │   ├── AppLayout.vue              # Layout principal
│       │   ├── AppHeader.vue
│       │   ├── AppSidebar.vue
│       │   └── ui/*.vue                   # Componentes UI (shadcn-like)
│       ├── composables/useIncomingMessageSound.ts
│       ├── bootstrap.ts
│       └── echo.ts                        # Inicialización de Laravel Echo con Pusher
│
├── public/
│   ├── Audios/NECESITA_ASESOR.mp3         # Alertas de sonido
│   ├── Audios/VERIFICARYAPE.mp3
│   └── img/LogoRomaStore.png
│
├── database/
│   └── migrations/                        # Tablas: clients, messages, orders, products, etc.
│
├── .env                                   # Configuración
│   ROMA_API_ENABLED=true
│   ROMA_API_URL=https://silkworm-humorous-properly.ngrok-free.app
│   ROMA_SYNC_TOKEN=roma_sync_secret_2026
│   BROADCAST_CONNECTION=pusher
│   PUSHER_APP_ID=2156513
│   PUSHER_APP_KEY=94079582c942034854e5
│   PUSHER_APP_SECRET=fbb8d7b173bff61d0dd1
│   PUSHER_APP_CLUSTER=us2
│
└── package.json                            # Vue, Vite, Inertia, Tailwind, Pinia, Pusher
```

## Flujo Principal

### 1. Recepción y Actualización en Tiempo Real (Meta → roma-api → Laravel)
- El webhook de Meta WhatsApp envía los mensajes entrantes al servidor `roma-api` (Next.js).
- `roma-api` realiza un POST a `/api/roma/messages` en Laravel.
- Laravel procesa e importa el mensaje, y despacha en segundo plano dos eventos transmitidos por **Pusher**:
  - `MessageReceived` en el canal público `client.{clientId}`.
  - `ClientStatusUpdated` en el canal público `crm-dashboard`.
- El cliente (navegador web) escucha estos canales a través de **Laravel Echo** en el store global de **Pinia** (`crm.ts`) y actualiza reactivamente los mensajes y clientes en pantalla al instante, **sin necesidad de recargar la página (F5)**.

### 2. Envío de Mensajes (Laravel → roma-api → Meta)
- Cuando el vendedor responde desde el CRM local, `WhatsAppService::sendMessage()` detecta que `roma-api` está habilitado.
- Laravel realiza un POST al endpoint `{roma_api_url}/api/messages` omitiendo la cabecera `X-Roma-Source: laravel` para indicar que delegamos el envío.
- El servidor remoto `roma-api` (que cuenta con el token de Meta válido) recibe la petición y envía el mensaje real al cliente de WhatsApp.

### 3. CRM Dashboard (Vue + Inertia + Pinia)
- Toda la lógica reactiva (clientes, mensajes, búsqueda, inputs, envío) se gestiona de manera centralizada en el store global de Pinia (`resources/js/stores/crm.ts`).
- Se eliminó el polling periódico de intervalos (`setInterval`) para prevenir la saturación de recursos en hosting compartido. Al abrir una conversación se realiza una única consulta inicial para sincronizar el historial, y a partir de ahí la actualización es pasiva y puramente dirigida por WebSockets (Pusher) con **0% de consumo de CPU** en el servidor de hosting.

## Integraciones

| Servicio | Uso |
|----------|-----|
| Meta WhatsApp Business API | Envío/recepción de mensajes (vía roma-api) |
| roma-api (Next.js) | Enlace y pasarela de envío con Meta, ngrok |
| Pusher | Transmisión de eventos de WebSocket en tiempo real |
| Gemini 2.0 Flash | IA para respuestas y detección de intenciones |
| Pinia | Gestión de estado reactivo global en Vue 3 |
| Inertia.js + Vue | Frontend interactivo SPA |
