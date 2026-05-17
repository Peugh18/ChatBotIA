<?php

namespace App\Services;

use App\Models\DeliveryZone;
use App\Models\Product;

/**
 * Local NLP-lite detector. Avoids hitting Gemini for trivial messages
 * (greetings, FAQs, intents) — saves quota, latency and money.
 *
 * Returns ['type' => string, ...payload] or null to fall through to AI.
 */
class IntentDetector
{
    /** Negocio config (puede moverse a config/business.php) */
    public const BUSINESS_HOURS  = 'Lunes a Sábado de 10:00 a.m. a 8:00 p.m.';
    public const YAPE_NUMBER     = '912 874 650';
    public const YAPE_HOLDER     = 'Solange Llantoy';
    public const SHALOM_LIMA     = 10;   // S/.
    public const SHALOM_PROVINCIA = 12;   // S/. promedio
    public const MOTORIZADO_WINDOW = 'Lunes a Sábado de 5 p.m. a 9 p.m.';

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
        // Solo dispara si el mensaje menciona delivery/envío Y un distrito conocido.
        if ($this->containsAny($t, ['delivery', 'envio', 'envío', 'costo', 'cuanto cuesta', 'cuánto cuesta', 'motorizado', 'shalom'])) {
            $zone = $this->findDistrictMention($text);
            if ($zone) {
                return ['type' => 'delivery_quote', 'zone_id' => $zone->id];
            }
            return ['type' => 'delivery_info'];
        }

        // 6) Pide catálogo / listado
        if ($this->containsAny($t, ['catalogo', 'catálogo', 'que tienen', 'qué tienen', 'que venden', 'qué venden', 'que productos', 'qué productos', 'muestrame', 'muéstrame', 'lista de productos'])) {
            return ['type' => 'catalog_request'];
        }

        // 7) Gracias / despedida
        if ($this->matches($t, [
            '/^gracias[\s!.\?]*$/u', '/^muchas\s+gracias[\s!.\?]*$/u',
            '/^chau[\s!.\?]*$/u', '/^adios[\s!.\?]*$/u', '/^bye[\s!.\?]*$/u',
        ])) {
            return ['type' => 'thanks'];
        }

        // 8) Confirmaciones simples cuando el bot acaba de ofrecer algo
        // (Estas las maneja el flujo IA si hay state.product_id, no las
        //  resolvemos aquí para no romper contexto.)

        return null; // fall through to AI
    }

    /**
     * Extract product filters from a free-text message for sub-catalog search.
     * Returns ['name'?, 'color'?, 'size'?, 'category'?, 'max_price'?, 'min_price'?].
     */
    public function extractProductFilters(string $text): array
    {
        $t = $this->normalize($text);
        $filters = [];

        // Colors (extiende según tu inventario real)
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
                $filters['name'] = $val; // search en name/description
                break;
            }
        }

        // Rangos de precio: "hasta 80", "menos de 100", "máximo 120"
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
     * Returns the matching DeliveryZone (or null).
     */
    public function findDistrictMention(string $text): ?DeliveryZone
    {
        $t = $this->normalize($text);
        if ($t === '') return null;

        // Tokenize into candidate phrases (up to 4 words) and lookup by slug fragment.
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
        // collapse whitespace
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
