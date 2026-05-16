<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Message;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    protected $whatsAppService;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    public function index()
    {
        $clients = Client::orderBy('last_interaction_at', 'desc')->get();
        $products = Product::with(['variants', 'category'])->latest()->get();

        return Inertia::render('Dashboard', [
            'clients' => $clients,
            'products' => $products
        ]);
    }

    public function show(Client $client)
    {
        $messages = Message::where('client_id', $client->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $products = Product::with(['variants', 'category'])->latest()->get();
        $orders = Order::where('client_id', $client->id)->with('items.variant.product')->latest()->get();

        return Inertia::render('Dashboard', [
            'clients' => Client::orderBy('last_interaction_at', 'desc')->get(),
            'selectedClient' => $client,
            'messages' => $messages,
            'products' => $products,
            'orders' => $orders
        ]);
    }

    public function updateStatus(Request $request, Client $client)
    {
        $validated = $request->validate([
            'status' => 'required|string',
            'priority' => 'nullable|string'
        ]);

        $client->update($validated);

        return back()->with('success', 'Estado del cliente actualizado.');
    }

    public function sales()
    {
        $orders = Order::with(['client', 'items.variant.product'])->latest()->get();
        $totalSales = $orders->sum('total');
        $totalOrders = $orders->count();
        $completedOrders = $orders->where('status', 'COMPLETADA')->count();
        $pendingOrders = $orders->where('status', 'PENDIENTE')->count();

        return Inertia::render('SalesDashboard', [
            'orders' => $orders,
            'totalSales' => $totalSales,
            'totalOrders' => $totalOrders,
            'completedOrders' => $completedOrders,
            'pendingOrders' => $pendingOrders
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

        // 3. Decrement variant stock
        $variant->decrement('stock', $validated['quantity']);

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
}
