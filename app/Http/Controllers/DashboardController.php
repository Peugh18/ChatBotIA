<?php

namespace App\Http\Controllers;

use App\Events\ClientStatusUpdated;
use App\Events\MessageReceived;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\DeliveryZone;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use App\Services\Integration\RomaApiService;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DashboardController extends Controller
{
    protected $whatsAppService;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    public function index(RomaApiService $romaApi)
    {
        $this->syncFromRomaApi($romaApi);

        $clients      = Client::with('tags')->orderBy('last_interaction_at', 'desc')->get();
        $products     = Product::with(['variants', 'category'])->latest()->get();
        $allTags      = Tag::orderBy('name')->get();
        $quickReplies = QuickReply::orderBy('shortcut')->get();
        $users        = User::orderBy('name')->get(['id', 'name']);
        $deliveryZones = DeliveryZone::orderBy('district')->get();

        return Inertia::render('Dashboard', [
            'clients'      => $clients,
            'products'     => $products,
            'allTags'      => $allTags,
            'quickReplies' => $quickReplies,
            'users'        => $users,
            'deliveryZones'=> $deliveryZones,
        ]);
    }

    public function show(Client $client, RomaApiService $romaApi)
    {
        $this->syncFromRomaApi($romaApi);

        $client->load(['tags', 'notes' => fn($q) => $q->latest(), 'notes.user', 'assignedUser']);

        $messages = Message::where('client_id', $client->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $products = Product::with(['variants', 'category'])->latest()->get();
        $orders   = Order::where('client_id', $client->id)->with('items.variant.product')->latest()->get();

        return Inertia::render('Dashboard', [
            'clients'        => Client::with('tags')->orderBy('last_interaction_at', 'desc')->get(),
            'selectedClient' => $client,
            'messages'       => $messages,
            'products'       => $products,
            'orders'         => $orders,
            'allTags'        => Tag::orderBy('name')->get(),
            'quickReplies'   => QuickReply::orderBy('shortcut')->get(),
            'users'          => User::orderBy('name')->get(['id', 'name']),
            'clientNotes'    => $client->notes,
            'deliveryZones'  => DeliveryZone::orderBy('district')->get(),
        ]);
    }

    /** Send a manual WhatsApp message from the CRM (vendor speaking). */
    public function sendMessage(Request $request, Client $client)
    {
        $validated = $request->validate([
            'body' => 'required|string|max:4000',
        ]);

        try {
            $waId = $this->whatsAppService->sendMessage($client->phone, $validated['body']);
            $msg = Message::create([
                'client_id'       => $client->id,
                'from_me'         => true,
                'body'            => $validated['body'],
                'type'            => 'text',
                'meta_message_id' => $waId,
            ]);
            broadcast(new MessageReceived($msg))->toOthers();
            broadcast(new ClientStatusUpdated($client->fresh()))->toOthers();
            $client->update(['last_interaction_at' => now()]);

            if (Schema::hasColumn('clients', 'first_response_at') && empty($client->first_response_at)) {
                $client->forceFill(['first_response_at' => now()])->save();
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $this->formatMessage($msg),
                    'client'  => $client->fresh()->only(['id', 'status', 'last_interaction_at']),
                ]);
            }
        } catch (\Throwable $e) {
            \Log::error('CRM sendMessage failed: ' . $e->getMessage());

            if ($request->wantsJson()) {
                return response()->json(['error' => 'No se pudo enviar el mensaje.'], 422);
            }

            return back()->withErrors(['message' => 'No se pudo enviar el mensaje. Revisa la consola.']);
        }

        return back()->with('success', 'Mensaje enviado.');
    }

    /** JSON poll: sync roma-api + devuelve mensajes (sin recargar toda la página). */
    public function pollChat(Client $client, RomaApiService $romaApi)
    {
        $sync = ['imported' => 0, 'skipped' => 0, 'total' => 0];
        if ($romaApi->isEnabled()) {
            $sync = $romaApi->pullLatestMessages(
                (int) config('services.roma_api.pull_limit', 50),
                $client->phone
            );
        }

        $client->load('tags');

        $messages = Message::where('client_id', $client->id)
            ->orderBy('created_at', 'asc')
            ->get(['id', 'body', 'from_me', 'created_at']);

        return response()->json([
            'messages' => $messages->map(fn (Message $m) => $this->formatMessage($m)),
            'client'   => [
                'id'     => $client->id,
                'status' => $client->status,
                'name'   => $client->name,
                'phone'  => $client->phone,
            ],
            'sync'     => $sync,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatMessage(Message $message): array
    {
        return [
            'id'         => $message->id,
            'body'       => $message->body,
            'from_me'    => (bool) $message->from_me,
            'created_at' => $message->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    /** Assign (or unassign with null) a vendor user to a client. */
    public function assign(Request $request, Client $client)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
        ]);

        $client->update(['assigned_user_id' => $validated['user_id'] ?? null]);

        return back()->with('success', 'Cliente asignado.');
    }

    public function updateStatus(Request $request, Client $client)
    {
        $validated = $request->validate([
            'status' => 'required|string',
            'priority' => 'nullable|string'
        ]);

        $client->update($validated);
        broadcast(new ClientStatusUpdated($client->fresh()))->toOthers();

        return back()->with('success', 'Estado del cliente actualizado.');
    }

    public function sales()
    {
        $orders = Order::with(['client', 'items.variant.product'])->latest()->get();

        // ── KPIs primarios ──────────────────────────────────────────────────
        $totalSales      = (float) $orders->sum('total');
        $totalOrders     = $orders->count();
        $completedOrders = $orders->whereIn('status', ['PAGADO', 'ENTREGADO', 'PAGO RECIBIDO'])->count();
        $pendingOrders   = $orders->whereIn('status', ['PENDIENTE', 'ENVIADO'])->count();

        // Average Order Value
        $aov = $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0;

        // ── Repeat-buyer rate ────────────────────────────────────────────────
        $clientsWithAnyOrder    = Client::has('orders')->count();
        $clientsWithMultiple    = Client::has('orders', '>=', 2)->count();
        $repeatRate             = $clientsWithAnyOrder > 0
            ? round(($clientsWithMultiple / $clientsWithAnyOrder) * 100, 1)
            : 0;

        // ── Conversion funnel ────────────────────────────────────────────────
        $funnel = [
            ['stage' => 'Nuevos',          'count' => Client::where('status', 'NUEVO')->count()],
            ['stage' => 'Interesados',     'count' => Client::where('status', 'INTERESADO')->count()],
            ['stage' => 'Consultando',     'count' => Client::where('status', 'CONSULTANDO')->count()],
            ['stage' => 'Esperando Pago',  'count' => Client::where('status', 'ESPERANDO PAGO')->count()],
            ['stage' => 'Pago Recibido',   'count' => Client::where('status', 'PAGO RECIBIDO')->count()],
            ['stage' => 'Finalizado',      'count' => Client::whereIn('status', ['FINALIZADO', 'VENTA CUMPLIDA'])->count()],
        ];

        $totalClients     = Client::count();
        $convertedClients = Client::whereIn('status', ['ESPERANDO PAGO', 'PAGO RECIBIDO', 'ENTREGADO', 'FINALIZADO', 'VENTA CUMPLIDA'])->count();
        $conversionRate   = $totalClients > 0 ? round(($convertedClients / $totalClients) * 100, 1) : 0;

        // ── Revenue por día (últimos 14 días) ────────────────────────────────
        $since = now()->subDays(13)->startOfDay();
        $byDay = Order::where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, SUM(total) as revenue, COUNT(*) as orders_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $revenueSeries = [];
        for ($i = 0; $i < 14; $i++) {
            $day = now()->subDays(13 - $i)->format('Y-m-d');
            $revenueSeries[] = [
                'day'     => $day,
                'revenue' => (float) ($byDay[$day]->revenue ?? 0),
                'orders'  => (int)   ($byDay[$day]->orders_count ?? 0),
            ];
        }

        // ── Tiempo de primera respuesta (TFR) ────────────────────────────────
        // Promedia minutos entre creación del cliente y first_response_at.
        $tfrMinutes = null;
        if (Schema::hasColumn('clients', 'first_response_at')) {
            $driver = DB::getDriverName();
            if ($driver === 'sqlite') {
                $selectSql = 'AVG((strftime("%s", first_response_at) - strftime("%s", created_at)) / 60) as avg_min';
            } else {
                $selectSql = 'AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) as avg_min';
            }
            $tfrMinutes = (float) DB::table('clients')
                ->whereNotNull('first_response_at')
                ->selectRaw($selectSql)
                ->value('avg_min');
            $tfrMinutes = $tfrMinutes ? round($tfrMinutes, 1) : null;
        }

        // ── Leads calientes (score >= 70 + status no cerrado) ────────────────
        $hotLeads = [];
        if (Schema::hasColumn('clients', 'lead_score')) {
            $hotLeads = Client::where('lead_score', '>=', 70)
                ->whereNotIn('status', ['FINALIZADO', 'VENTA CUMPLIDA'])
                ->orderByDesc('lead_score')
                ->limit(10)
                ->get(['id', 'name', 'phone', 'status', 'lead_score', 'last_interaction_at']);
        }

        // ── Top productos ───────────────────────────────────────────────────
        $topProducts = Product::with('variants')
            ->orderByDesc('sales_count')
            ->limit(5)
            ->get()
            ->map(fn($p) => [
                'name'        => $p->name,
                'sales_count' => $p->sales_count,
                'price'       => $p->price,
                'stock'       => $p->variants->sum('stock'),
            ]);

        // ── Salud del stock: variantes en cero o bajo ────────────────────────
        $lowStockCount = ProductVariant::where('stock', '<', 3)->count();

        return Inertia::render('SalesDashboard', [
            'orders'                  => $orders,
            'totalSales'              => $totalSales,
            'totalOrders'             => $totalOrders,
            'completedOrders'         => $completedOrders,
            'pendingOrders'           => $pendingOrders,
            'aov'                     => $aov,
            'repeatRate'              => $repeatRate,
            'funnel'                  => $funnel,
            'conversionRate'          => $conversionRate,
            'topProducts'             => $topProducts,
            'revenueSeries'           => $revenueSeries,
            'firstResponseAvgMinutes' => $tfrMinutes,
            'hotLeads'                => $hotLeads,
            'lowStockCount'           => $lowStockCount,
        ]);
    }

    public function createOrder(Request $request, Client $client)
    {
        $validated = $request->validate([
            'variant_id' => 'required|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
            'status' => 'required|string', // PENDIENTE, COMPLETADA
            'shipping_address' => 'nullable|string',
            'shipping_method' => 'nullable|string'
        ]);

        $variant = ProductVariant::with('product')->find($validated['variant_id']);

        // Check if we have enough stock
        if ($variant->stock < $validated['quantity']) {
            return back()->withErrors(['stock' => "Stock insuficiente para {$variant->product->name} (Color: {$variant->color}, Talla: {$variant->size})."]);
        }

        // 1. Create the order
        $order = Order::create([
            'client_id' => $client->id,
            'total' => $variant->product->price * $validated['quantity'],
            'status' => $validated['status'],
            'shipping_address' => $validated['shipping_address'] ?? 'Entrega en tienda / Directa',
            'shipping_method' => $validated['shipping_method'] ?? 'Directo'
        ]);

        // 2. Create the order item
        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity' => $validated['quantity'],
            'price' => $variant->product->price
        ]);

        // 3. Decrement variant stock + increment product sales counter
        $variant->decrement('stock', $validated['quantity']);
        $variant->product->increment('sales_count', $validated['quantity']);

        // Maintain client lifetime value
        if (Schema::hasColumn('clients', 'lifetime_value')) {
            $client->increment('lifetime_value', $order->total);
        }

        // 4. If status is COMPLETADA, let's also update the client status to VENTA CUMPLIDA!
        if ($validated['status'] === 'COMPLETADA') {
            $client->update(['status' => 'VENTA CUMPLIDA']);
        }

        // 5. Send automated confirmation WhatsApp message
        try {
            $msg = "🛍️ *¡Pedido Registrado con Éxito!*\n\n" .
                   "Hola *{$client->name}*, se ha registrado una venta para ti:\n" .
                   "🔹 *Producto:* {$variant->product->name}\n" .
                   "🔹 *Color:* {$variant->color}\n" .
                   "🔹 *Talla:* {$variant->size}\n" .
                   "🔹 *Cantidad:* {$validated['quantity']}\n" .
                   "💰 *Total:* S/ " . number_format($order->total, 2) . "\n\n" .
                   "¡Muchas gracias por elegir Roma Store! ✨";
            
            $this->whatsAppService->sendMessage($client->phone, $msg);
            
            // Log outgoing message in DB
            Message::create([
                'client_id' => $client->id,
                'from_me' => true,
                'body' => $msg,
                'type' => 'text'
            ]);
        } catch (\Exception $e) {
            \Log::error("Error al enviar WhatsApp de confirmación: " . $e->getMessage());
        }

        return back()->with('success', 'Pedido creado con éxito y stock actualizado.');
    }

    public function approvePayment(Request $request, Client $client)
    {
        if ($client->status !== 'VERIFICARYAPE') {
            return back()->withErrors(['message' => 'El cliente no está en estado VERIFICARYAPE.']);
        }

        $state = $client->state ?? ['step' => 'start'];
        $state['payment_receipt_url'] = $client->payment_receipt_url;
        $state['paid_amount'] = $client->paid_amount;

        if (($state['shipping'] ?? null) === 'Motorizado') {
            $state['step'] = 'collect_delivery_details_motorizado';
        } else {
            $state['step'] = 'collect_delivery_details_shalom';
        }

        $client->update([
            'state' => $state,
            'status' => 'PAGO RECIBIDO',
            'priority' => 'ALTA',
            'payment_verified_by' => auth()->id(),
            'payment_verified_at' => now(),
        ]);

        broadcast(new ClientStatusUpdated($client->fresh()))->toOthers();

        try {
            $msg = "¡Tu pago fue verificado correctamente, hermosa! ✅\n\n"
                 . "Ahora necesitamos tus datos de envío para programarlo. ";
            if (($state['shipping'] ?? null) === 'Motorizado') {
                $msg .= "Envíame: Nombre completo, celular, dirección escrita y tu ubicación en tiempo real. 🛵";
            } else {
                $msg .= "Envíame: Nombre completo, DNI, celular y sede exacta de Shalom. 🚚";
            }
            $this->whatsAppService->sendMessage($client->phone, $msg);
            Message::create([
                'client_id' => $client->id,
                'from_me' => true,
                'body' => $msg,
                'type' => 'text',
            ]);
        } catch (\Exception $e) {
            \Log::error("Error al enviar WhatsApp de aprobación: " . $e->getMessage());
        }

        return back()->with('success', 'Pago aprobado.');
    }

    public function rejectPayment(Request $request, Client $client)
    {
        if ($client->status !== 'VERIFICARYAPE') {
            return back()->withErrors(['message' => 'El cliente no está en estado VERIFICARYAPE.']);
        }

        $state = $client->state ?? ['step' => 'start'];
        $state['payment_receipt_url'] = null;
        $state['paid_amount'] = null;

        $client->update([
            'state' => $state,
            'status' => 'ESPERANDO PAGO',
            'priority' => 'ALTA',
            'payment_receipt_url' => null,
            'paid_amount' => null,
            'payment_verified_by' => auth()->id(),
            'payment_verified_at' => now(),
        ]);

        broadcast(new ClientStatusUpdated($client->fresh()))->toOthers();

        try {
            $msg = "Hermosa, revisé tu comprobante y no pude validar el pago 😅\n\n"
                 . "Por favor envíame una nueva captura clara del Yape para poder procesar tu pedido. 💕";
            $this->whatsAppService->sendMessage($client->phone, $msg);
            Message::create([
                'client_id' => $client->id,
                'from_me' => true,
                'body' => $msg,
                'type' => 'text',
            ]);
        } catch (\Exception $e) {
            \Log::error("Error al enviar WhatsApp de rechazo: " . $e->getMessage());
        }

        return back()->with('success', 'Pago rechazado. Se solicitó nuevo comprobante.');
    }

    public function syncRoma(RomaApiService $romaApi)
    {
        if (! $romaApi->isEnabled()) {
            return back()->withErrors(['roma' => 'roma-api no está habilitado en .env']);
        }

        $result = $romaApi->pullLatestMessages((int) config('services.roma_api.pull_limit', 50));

        if (isset($result['error'])) {
            return back()->withErrors(['roma' => $result['error']]);
        }

        return back()->with('success', "Sincronizado: {$result['imported']} nuevos, {$result['skipped']} ya existían.");
    }

    protected function syncFromRomaApi(RomaApiService $romaApi): void
    {
        if (! $romaApi->isEnabled()) {
            return;
        }

        $romaApi->pullLatestMessages((int) config('services.roma_api.pull_limit', 50));
    }
}
