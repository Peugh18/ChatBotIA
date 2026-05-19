<?php

namespace App\Services\Messaging;

use App\Models\Client;
use App\Models\Product;
use App\Models\Message;
use App\Models\Category;
use App\Events\MessageReceived;
use App\Support\SafeBroadcast;
use App\Services\Utility\ImageService;
use App\Services\Business\ProductSearchService;

class WhatsAppMessageService
{
    protected WhatsAppService $whatsAppService;
    protected ImageService $imageService;
    protected ProductSearchService $searchService;

    public function __construct(
        WhatsAppService $whatsAppService,
        ImageService $imageService,
        ProductSearchService $searchService
    ) {
        $this->whatsAppService = $whatsAppService;
        $this->imageService = $imageService;
        $this->searchService = $searchService;
    }

    public function markAsRead(string $messageId): void
    {
        $this->whatsAppService->markAsRead($messageId);
    }

    /**
     * Send a text message and store it in the database with broadcast.
     */
    public function reply(Client $client, string $body): void
    {
        $this->whatsAppService->sendMessage($client->phone, $body);

        $msg = Message::create([
            'client_id' => $client->id,
            'from_me'   => true,
            'body'      => $body,
            'type'      => 'text',
        ]);

        SafeBroadcast::event(new MessageReceived($msg));
    }

    /**
     * Send interactive buttons and store the message.
     */
    public function sendButtons(Client $client, string $bodyText, array $buttonTitles): void
    {
        $this->whatsAppService->sendButtons($client->phone, $bodyText, $buttonTitles);

        $msg = Message::create([
            'client_id' => $client->id,
            'from_me'   => true,
            'body'      => $bodyText,
            'type'      => 'button',
            'metadata'  => json_encode(['buttons' => $buttonTitles]),
        ]);

        SafeBroadcast::event(new MessageReceived($msg));
    }

    /**
     * Send category list and store the message.
     */
    public function sendCategoryList(Client $client): void
    {
        $categories = Category::orderBy('name')->get();
        if ($categories->isEmpty()) {
            $this->reply($client, "Por ahora no tenemos categorías disponibles 😅");
            return;
        }

        $sections = [];
        foreach ($categories as $cat) {
            $sections[] = [
                'title' => $cat->name,
                'rows'  => $cat->products()
                    ->whereHas('variants', fn ($q) => $q->where('stock', '>', 0))
                    ->limit(5)
                    ->get()
                    ->map(fn($p) => ['id' => "prod_{$p->id}", 'title' => $p->name, 'description' => "S/ {$p->price}"])
                    ->toArray(),
            ];
        }

        $this->whatsAppService->sendListMessage($client->phone, '🛍️ Catálogo Roma Store', 'Elige una categoría:', $sections);

        $msg = Message::create([
            'client_id' => $client->id,
            'from_me'   => true,
            'body'      => '🛍️ Catálogo Roma Store',
            'type'      => 'list',
            'metadata'  => json_encode(['categories' => $categories->pluck('name')->toArray()]),
        ]);

        SafeBroadcast::event(new MessageReceived($msg));
    }

    /**
     * Send product card and store the message.
     */
    public function sendProductCard(Client $client, Product $product): void
    {
        $product->loadMissing('variants');
        $inStock = $product->variants->where('stock', '>', 0)->isNotEmpty();

        $media = $product->image_url;
        if (!$media || !str_starts_with($media, 'http')) {
            $media = null;
        }

        $price = (float) $product->price;
        if (!empty($product->discount_percent) && $product->discount_percent > 0) {
            $final = round($price * (1 - $product->discount_percent / 100), 2);
            $priceText = "~S/ " . number_format($price, 2) . "~ → *S/ " . number_format($final, 2) . "*";
        } else {
            $priceText = 'S/ ' . number_format($price, 2);
        }

        $body = "*{$product->name}*\n{$priceText}\n" . mb_strimwidth($product->description ?? '', 0, 120, '…');
        $buttons = [
            ['type' => 'reply', 'reply' => ['id' => 'buy_' . $product->id, 'title' => 'Lo quiero 💚']],
        ];
        if ($inStock) {
            $buttons[] = ['type' => 'reply', 'reply' => ['id' => 'colors_' . $product->id, 'title' => 'Ver colores']];
        }

        $this->whatsAppService->sendProductCard($client->phone, $product->name, $body, $media, $buttons);

        $msg = Message::create([
            'client_id' => $client->id,
            'from_me'   => true,
            'body'      => $product->name,
            'type'      => 'card',
            'metadata'  => json_encode(['product_id' => $product->id, 'price' => $product->price]),
        ]);

        SafeBroadcast::event(new MessageReceived($msg));
    }

    /**
     * Send product info with variants.
     */
    public function sendProductInfo(Client $client, Product $product, ?string $intro = null): void
    {
        $product->loadMissing('variants');
        $inStock = $product->variants->where('stock', '>', 0);

        $variantSummary = $inStock->map(fn ($v) => "{$v->color} {$v->size}")->unique()->take(8)->implode(', ');

        $prefix = $intro ? $intro . "\n\n" : '';
        $msg = $prefix
            . "*{$product->name}*\n"
            . "💰 Precio: S/ {$product->price}\n"
            . ($product->description ? "📝 {$product->description}\n\n" : "\n")
            . "Disponible en: {$variantSummary}.\n\n"
            . "¡Nos confirmas si deseas realizar el pedido para poder ayudarte hermosa! ✨";

        $this->reply($client, $msg);

        if ($product->image_url && str_starts_with($product->image_url, 'http')) {
            $this->whatsAppService->sendImage($client->phone, $product->image_url, "Foto de *{$product->name}* ✨");
        }
    }

    /**
     * Send product photo.
     */
    public function sendProductPhoto(Client $client, int $productId): void
    {
        $product = Product::find($productId);
        if (!$product || !$product->image_url) {
            $this->reply($client, "Lo siento hermosa, no tengo foto disponible de ese producto 😅");
            return;
        }

        $this->whatsAppService->sendImage($client->phone, $product->image_url, "Aquí tienes la foto de *{$product->name}* ✨");
    }

    /**
     * Send upsell suggestion after purchase.
     */
    public function sendUpsellSuggestion(Client $client, Product $justBought): void
    {
        $similar = $this->searchService->getSimilar($justBought->id, 2);
        if ($similar->isEmpty()) return;

        $first = $similar->first();
        $this->reply($client,
            "💕 ¡Gracias por tu compra!\n\n"
            . "Por si te interesa, también tengo este modelo que combinaría perfecto:\n"
            . "*{$first->name}* - S/ {$first->price}\n\n"
            . "¿Te gustaría verlo?"
        );
    }
    /**
     * Send a WhatsApp template message.
     */
    public function sendTemplateMessage(Client $client, string $templateName, string $language = 'es', array $components = []): bool
    {
        $success = $this->whatsAppService->sendTemplate($client->phone, $templateName, $language, $components);

        if ($success) {
            $msg = Message::create([
                'client_id' => $client->id,
                'from_me'   => true,
                'body'      => "[Template: {$templateName}]",
                'type'      => 'template',
                'metadata'  => json_encode(['template' => $templateName, 'components' => $components]),
            ]);

            SafeBroadcast::event(new MessageReceived($msg));
        }

        return $success;
    }
}
