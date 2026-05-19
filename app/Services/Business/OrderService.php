<?php

namespace App\Services\Business;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OrderService
{
    /**
     * Creates and persists an order in a single transaction.
     * Locks the variant row to prevent overselling on concurrent requests.
     */
    public function createOrder(Client $client, array $state): ?Order
    {
        if (!isset($state['variant_id'])) {
            return null;
        }

        return DB::transaction(function () use ($client, $state) {
            $variant = ProductVariant::with('product')
                ->lockForUpdate()
                ->find($state['variant_id']);

            if (!$variant) {
                return null;
            }

            $quantity = $state['quantity'] ?? 1;

            if ($variant->stock < $quantity) {
                throw new \Exception('OUT_OF_STOCK');
            }

            // Calculate totals
            $productTotal = $variant->product->price * $quantity;
            $shippingCost = $state['shipping_cost'] ?? 0;
            $deliveryCost = $state['delivery_cost'] ?? 0;
            $total = $productTotal + $shippingCost + $deliveryCost;

            // Create order
            $order = Order::create([
                'client_id' => $client->id,
                'total' => $total,
                'status' => 'PENDIENTE',
                'shipping_address' => $state['delivery_details'] ?? 'Entrega en tienda / Directa',
                'shipping_method' => $state['shipping'] ?? 'Directo',
                'payment_receipt_url' => $state['payment_receipt_url'] ?? null,
            ]);

            // Create order item
            OrderItem::create([
                'order_id' => $order->id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'price' => $variant->product->price,
            ]);

            // Decrement variant stock
            $variant->decrement('stock', $quantity);

            // Increment product sales counter
            $variant->product->increment('sales_count', $quantity);

            // Update client lifetime value
            if (Schema::hasColumn('clients', 'lifetime_value')) {
                $client->increment('lifetime_value', $total);
            }

            return $order;
        });
    }

    /**
     * Finalizes an order after payment is verified.
     */
    public function finalizeOrder(Client $client, array $state): ?Order
    {
        try {
            $order = $this->createOrder($client, $state);

            if (!$order) {
                Log::error("Failed to create order for client {$client->id}");
                return null;
            }

            // Update order status to paid
            $order->update(['status' => 'PAGO RECIBIDO']);

            // Update client status
            $client->update([
                'status' => 'EN PREPARACIÓN',
                'priority' => 'ALTA',
            ]);

            return $order;
        } catch (\Exception $e) {
            Log::error('finalizeOrder failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Builds order confirmation message.
     */
    public function buildConfirmationMessage(Order $order, Client $client): string
    {
        $item = $order->items->first();
        $variant = $item ? $item->variant : null;
        $product = $variant ? $variant->product : null;

        $msg = "🛍️ *¡Pedido Registrado con Éxito!*\n\n"
             . "Hola *{$client->name}*, se ha registrado una venta para ti:\n";

        if ($product) {
            $msg .= "🔹 *Producto:* {$product->name}\n";
        }

        if ($variant) {
            $msg .= "🔹 *Color:* {$variant->color}\n"
                  . "🔹 *Talla:* {$variant->size}\n";
        }

        if ($item) {
            $msg .= "🔹 *Cantidad:* {$item->quantity}\n";
        }

        $msg .= "💰 *Total:* S/ " . number_format($order->total, 2) . "\n\n"
              . "¡Muchas gracias por elegir Roma Store! ✨";

        return $msg;
    }
}
