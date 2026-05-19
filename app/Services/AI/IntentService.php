<?php

namespace App\Services\AI;

use App\Models\DeliveryZone;
use App\Models\Setting;

/**
 * Intent Service - Detección de intenciones y configuración del negocio.
 * Fusiona funcionalidades de IntentDetector e IntentDetectionService.
 */
class IntentService
{
    /** Fallback defaults (used if settings table is empty) */
    public const BUSINESS_HOURS  = 'Lunes a Sábado de 10:00 a.m. a 8:00 p.m.';
    public const YAPE_NUMBER     = '912 874 650';
    public const YAPE_HOLDER     = 'Solange Llantoy';
    public const SHALOM_LIMA     = 10;
    public const SHALOM_PROVINCIA = 12;
    public const MOTORIZADO_WINDOW = 'Lunes a Sábado de 5 p.m. a 9 p.m.';

    // ── Configuration Methods (from IntentDetector) ──────────────────────────────

    public static function getBusinessHours(): string
    {
        return Setting::get('business_hours', self::BUSINESS_HOURS);
    }

    public static function getYapeNumber(): string
    {
        return Setting::get('yape_number', self::YAPE_NUMBER);
    }

    public static function getYapeHolder(): string
    {
        return Setting::get('yape_holder', self::YAPE_HOLDER);
    }

    public static function getShalomLima(): float
    {
        return (float) Setting::get('shalom_lima', self::SHALOM_LIMA);
    }

    public static function getShalomProvincia(): float
    {
        return (float) Setting::get('shalom_provincia', self::SHALOM_PROVINCIA);
    }

    public static function getMotorizadoWindow(): string
    {
        return Setting::get('motorizado_window', self::MOTORIZADO_WINDOW);
    }

    // ── Intent Detection Methods (from IntentDetector) ───────────────────────────

    /**
     * @return array{type:string, message?:string}|null
     */
    public function detect(string $text): ?array
    {
        $t = $this->normalize($text);
        if ($t === '') return null;

        // 1) Saludo
        if ($this->matches($t, [
            '/^hola[\s!.\?]*$/u', '/^hello[\s!.\?]*$/u', '/^hi[\s!.\?]*$/u',
            '/^buen[oa]s?(?:\s+(d[ií]as|tardes|noches))?[\s!.\?]*$/u',
            '/^q\s?onda$/u', '/^que\s?tal$/u', '/^holi[s]?$/u',
        ])) {
            return ['type' => 'greeting'];
        }

        // 2) Pide humano
        if ($this->containsAny($t, [
            'asesor', 'humano', 'persona real', 'agente', 'vendedor',
            'hablar con alguien', 'hablar contigo', 'persona', 'ejecutivo'
        ])) {
            return ['type' => 'escalate'];
        }

        // 3) Pregunta horario / atención
        if ($this->containsAny($t, ['horario', 'atencion', 'atención', 'a que hora', 'a qué hora', 'abren', 'cierran'])) {
            return ['type' => 'business_hours'];
        }

        // 4) Pregunta ubicación / dirección de la tienda
        if ($this->containsAny($t, ['donde estan', 'donde están', 'ubicacion', 'ubicación', 'direccion de la tienda', 'dirección de la tienda', 'donde queda', 'donde se ubica', 'tienen tienda fisica', 'tienen tienda física'])) {
            return ['type' => 'store_location'];
        }

        // 5) Yape / método de pago
        if ($this->containsAny($t, ['como pago', 'cómo pago', 'metodo de pago', 'método de pago', 'yape', 'plin', 'transferencia', 'numero de yape', 'número de yape', 'bcp', 'interbank', 'tarjeta', 'link de pago'])) {
            return ['type' => 'payment_info'];
        }

        // 5b) Consulta de costo de delivery por distrito.
        if ($this->containsAny($t, ['delivery', 'envio', 'envío', 'costo', 'cuanto cuesta', 'cuánto cuesta', 'motorizado', 'shalom'])) {
            $zone = $this->findDistrictMention($text);
            if ($zone) {
                return ['type' => 'delivery_quote', 'zone_id' => $zone->id];
            }
            return ['type' => 'delivery_info'];
        }

        // 6) Pide catálogo / listado
        if ($this->containsAny($t, [
            'catalogo', 'catálogo', 'que tienen', 'qué tienen', 'que venden', 'qué venden',
            'que vendes', 'qué vendes', 'que vende', 'qué vende', 'que paso', 'qué paso',
            'que productos', 'qué productos', 'que tienes', 'qué tienes', 'que hay', 'qué hay',
            'muestrame', 'muéstrame', 'ver catalogo', 'ver catálogo', 'lista de productos',
            'precios', 'modelos disponibles',
        ])) {
            return ['type' => 'catalog_request'];
        }

        // 7) Gracias / despedida
        if ($this->matches($t, [
            '/^gracias[\s!.\?]*$/u', '/^muchas\s+gracias[\s!.\?]*$/u',
            '/^chau[\s!.\?]*$/u', '/^adios[\s!.\?]*$/u', '/^bye[\s!.\?]*$/u',
        ])) {
            return ['type' => 'thanks'];
        }

        return null; // fall through to AI
    }

    // ── Pattern Detection Methods (from IntentDetectionService) ─────────────────

    /**
     * Detect if the client is asking for a confirmation.
     */
    public function looksLikeConfirmationRequest(string $message): bool
    {
        $t = mb_strtolower(trim($message));
        $keywords = ['confirmar', 'confirmación', 'si', 'sí', 'ok', 'dale', 'listo', 'ya', 'adelante', 'proceder', 'compro', 'quiero'];
        foreach ($keywords as $k) {
            if (str_contains($t, $k)) return true;
        }

        // Short ambiguous words: only match as standalone tokens (word boundary).
        if (preg_match('/(^|[^\p{L}])(sí|si|claro|dale|ok|okey|listo|compro|quiero)([^\p{L}]|$)/u', $t)) {
            return true;
        }

        return false;
    }

    /**
     * Detect if the client is expressing a buy intent.
     */
    public function looksLikeBuyIntent(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        if (preg_match('/\b(lo quiero|me lo llevo|compro|comprar|dame ese|apartar|reservar|sí lo quiero|si lo quiero)\b/u', $t)) {
            return true;
        }

        return false;
    }

    /**
     * Client confirmed they want to proceed with the product in context.
     */
    public function looksLikeOrderConfirmation(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        if (preg_match('/\b(sí|si|dale|ok|okey|listo|confirmo|adelante|sí quiero|si quiero|lo quiero|compro|de acuerdo)\b/u', $t)) {
            return true;
        }

        return str_contains($t, 'sí, lo quiero') || str_contains($t, 'si, lo quiero');
    }

    public function looksLikeRejection(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return (bool) preg_match('/\b(no|todavía no|aun no|aún no|ver otros|otro modelo|otra opción|después|luego)\b/u', $t);
    }

    /**
     * Detect if the client is asking for a photo/image of the product.
     */
    public function looksLikePhotoRequest(string $text): bool
    {
        $keywords = ['foto', 'imagen', 'ver foto', 'muestrame foto', 'muéstrame foto', 'fotos', 'imágenes', 'ver imagen', 'mandame foto', 'mándame foto', 'pasame foto', 'pásame foto', 'envia foto', 'envía foto'];
        foreach ($keywords as $k) {
            if (str_contains(mb_strtolower($text), $k)) return true;
        }
        return false;
    }

    /**
     * Detect if the client is promising to send a photo.
     */
    public function looksLikePhotoPromise(string $message): bool
    {
        $t = mb_strtolower(trim($message));
        $keywords = ['ahora te la paso', 'ahora te la mando', 'en un momento', 'luego te la paso', 'luego te la mando', 'te la envío', 'te la envio'];
        foreach ($keywords as $k) {
            if (str_contains($t, $k)) return true;
        }
        return false;
    }

    // ── Product Filter Extraction (from IntentDetector) ───────────────────────

    /**
     * Extract product filters from a free-text message for sub-catalog search.
     * Returns ['name'?, 'color'?, 'size'?, 'category'?, 'max_price'?, 'min_price'?].
     */
    public function extractProductFilters(string $text): array
    {
        $t = $this->normalize($text);
        $filters = [];

        // Colors
        $colorMap = [
            'negro' => 'negro', 'blanco' => 'blanco', 'rojo' => 'rojo',
            'azul' => 'azul', 'celeste' => 'celeste', 'verde' => 'verde',
            'amarillo' => 'amarillo', 'rosa' => 'rosa', 'rosado' => 'rosa',
            'morado' => 'morado', 'lila' => 'lila', 'marron' => 'marrón',
            'marrón' => 'marrón', 'gris' => 'gris', 'beige' => 'beige',
            'crema' => 'crema', 'naranja' => 'naranja',
        ];
        foreach ($colorMap as $key => $val) {
            if (str_contains($t, $key)) { $filters['color'] = $val; break; }
        }

        // Tallas
        if (preg_match('/\b(xs|s|m|l|xl|xxl|2xl)\b/iu', $t, $m)) {
            $filters['size'] = strtoupper($m[1]);
        } elseif (preg_match('/talla\s+(\d{2,3})/u', $t, $m)) {
            $filters['size'] = $m[1];
        }

        // Categorías comunes de ropa
        $categoryHints = [
            'polo' => 'polo', 'polos' => 'polo',
            'casaca' => 'casaca', 'casacas' => 'casaca',
            'pantalon' => 'pantalon', 'pantalón' => 'pantalon', 'pantalones' => 'pantalon',
            'jean' => 'jean', 'jeans' => 'jean',
            'short' => 'short', 'shorts' => 'short',
            'falda' => 'falda', 'faldas' => 'falda',
            'vestido' => 'vestido', 'vestidos' => 'vestido',
            'blusa' => 'blusa', 'blusas' => 'blusa',
            'camisa' => 'camisa', 'camisas' => 'camisa',
            'chompa' => 'chompa', 'chompas' => 'chompa',
            'hoodie' => 'hoodie', 'polera' => 'polera',
            'abrigo' => 'abrigo', 'abrigos' => 'abrigo',
            'zapato' => 'zapato', 'zapatos' => 'zapato', 'zapatilla' => 'zapatilla', 'zapatillas' => 'zapatilla',
        ];
        foreach ($categoryHints as $key => $val) {
            if (preg_match('/\b' . preg_quote($key, '/') . '\b/u', $t)) {
                $filters['name'] = $val;
                break;
            }
        }

        // Rangos de precio
        if (preg_match('/(?:hasta|menos\s+de|maximo|máximo|max)\s+(?:s\/\s*)?(\d{1,5})/u', $t, $m)) {
            $filters['max_price'] = (int) $m[1];
        }
        if (preg_match('/(?:desde|mas\s+de|más\s+de|minimo|mínimo|min)\s+(?:s\/\s*)?(\d{1,5})/u', $t, $m)) {
            $filters['min_price'] = (int) $m[1];
        }
        if (preg_match('/entre\s+(\d{1,5})\s+y\s+(\d{1,5})/u', $t, $m)) {
            $filters['min_price'] = (int) $m[1];
            $filters['max_price'] = (int) $m[2];
        }

        return $filters;
    }

    /**
     * Try to match a district name from the message against the delivery_zones table.
     */
    public function findDistrictMention(string $text): ?DeliveryZone
    {
        $t = $this->normalize($text);
        if ($t === '') return null;

        $words = preg_split('/\s+/u', $t) ?: [];
        $candidates = [];
        $n = count($words);
        for ($size = min(4, $n); $size >= 1; $size--) {
            for ($i = 0; $i <= $n - $size; $i++) {
                $candidates[] = implode(' ', array_slice($words, $i, $size));
            }
        }

        foreach ($candidates as $phrase) {
            $zone = DeliveryZone::findByName($phrase);
            if ($zone) return $zone;
        }
        return null;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $text);
    }

    protected function matches(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (preg_match($p, $haystack)) return true;
        }
        return false;
    }

    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) return true;
        }
        return false;
    }
}
