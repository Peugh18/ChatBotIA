<?php

namespace Database\Seeders;

use App\Models\QuickReply;
use Illuminate\Database\Seeder;

class QuickReplySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['shortcut' => '/confirmar', 'title' => 'Cierre de venta (frase oficial)',
             'body' => "¿Nos confirmas si deseas realizar el pedido para poder ayudarte hermosa? 💛"],

            ['shortcut' => '/yape',     'title' => 'Pago por Yape',
             'body' => "💸 *Yape* a este mismo número:\n\n*912 874 650* — *Solange Llantoy*\n\nUna vez hagas el pago, envíame la *captura* y confirmamos tu pedido al instante ⚡"],

            ['shortcut' => '/tarjeta',  'title' => 'Pago con tarjeta / link',
             'body' => "Perfecto, te genero el link de pago. Por favor envíame:\n\n• *Nombre completo*\n• *Correo electrónico*\n• *Número de celular*\n• *Monto a pagar*"],

            ['shortcut' => '/envio',    'title' => 'Opciones de envío',
             'body' => "📦 *¿Cómo prefieres recibir tu pedido?*\n\n🏍️ *Motorizado* (Lima, L–S 5–9 p.m., tarifa según distrito).\n📦 *Shalom* (Lima S/ 10 · Provincia ~S/ 12).\n\n¿A qué distrito te lo enviamos? Así te paso el costo exacto."],

            ['shortcut' => '/motorizado', 'title' => 'Datos para envío motorizado',
             'body' => "🏍️ *Datos para envío por motorizado:*\n\n✅ NOMBRE DEL VESTIDO Y COLOR:\n✅ NOMBRE COMPLETO:\n✅ CELULAR:\n✅ DIRECCIÓN ESCRITA:\n✅ UBICACIÓN EN TIEMPO REAL:\n\nLas entregas son de *L–S, 5–9 p.m.* El motorizado se paga aparte al recibir el vestido."],

            ['shortcut' => '/shalom',   'title' => 'Datos para envío Shalom',
             'body' => "📦 *Datos para envío por Shalom:*\n\n✅ Nombre del vestido y color:\n✅ Nombre completo:\n✅ Número de DNI:\n✅ Número de celular:\n✅ Sede exacta de Shalom:"],

            ['shortcut' => '/recordatorio_3', 'title' => 'Follow-up 3 minutos (sin respuesta)',
             'body' => "Hermosa, nos confirmas si vas a realizar el pedido por favor 💛"],

            ['shortcut' => '/recordatorio_15', 'title' => 'Follow-up 15 minutos (sin respuesta)',
             'body' => "Muchas gracias hermosa, cualquier cosita si te animas más tarde nos escribes. Que tengas un gran día 🤗🤗"],

            ['shortcut' => '/datos_pendientes', 'title' => 'Recordatorio datos de envío',
             'body' => "Hermosa, por favor sus datos para poder programar el envío �"],

            ['shortcut' => '/escalar',  'title' => 'Escalar a asesor humano',
             'body' => "Voy a realizar la consulta a un asesor especializado y en breve te brindamos una respuesta 🙌"],

            ['shortcut' => '/gracias',  'title' => 'Despedida cálida',
             'body' => "¡Gracias por tu compra! 💛 Cualquier cosa estoy por aquí. Ojalá pronto te vea de nuevo por la tienda ✨"],

            ['shortcut' => '/horario',  'title' => 'Horario de atención',
             'body' => "� Atendemos por WhatsApp de *Lunes a Sábado 10:00 a.m. – 8:00 p.m.* Si me escribes fuera de horario te respondo apenas abramos 😊"],

            ['shortcut' => '/pago_ok',  'title' => 'Confirmación de pago',
             'body' => "✅ ¡Pago confirmado! Tu pedido entra a despacho ahora. En breve te paso los detalles del envío."],

            ['shortcut' => '/oferta',   'title' => 'Oferta + urgencia',
             'body' => "🔥 Aprovecha: este modelo tiene *stock limitado* esta semana. Si lo apartas hoy, te lo despachamos mañana sin recargo."],
        ];

        foreach ($defaults as $data) {
            QuickReply::updateOrCreate(['shortcut' => $data['shortcut']], $data);
        }
    }
}
