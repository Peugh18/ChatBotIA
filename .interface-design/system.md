# Roma ABC CRM — Design System

## Direction & Feel
WhatsApp CRM para TikTok Live en Perú. Oscuro, profesional, centrado en la bandeja de chats. La interfaz prioriza la lectura rápida de conversaciones y el acceso simultáneo a datos del cliente y pedidos.

## Palette
- **Background**: `bg-gray-900` (base), `bg-gray-800` (elevated cards), `bg-gray-700` (hover/active states)
- **Foreground**: `text-gray-100` (primary), `text-gray-400` (secondary), `text-gray-500` (muted)
- **Borders**: `border-gray-800` (between sections), `border-gray-700/50` (cards), `border-gray-600` (inputs)
- **Inputs**: `bg-gray-900 border-gray-600` (inset), `focus:border-gray-400`
- **Accent (white)**: Buttons primarios, selects activos — `bg-white text-gray-900`
- **Statuses**: `bg-blue-400` (NUEVO), `bg-yellow-400` (INTERESADO), `bg-orange-400` (CONSULTANDO), `bg-purple-400` (ESPERANDO PAGO/VERIFICARYAPE), `bg-emerald-400` (PAGO RECIBIDO/VENTA CUMPLIDA), `bg-red-400` (NECESITA ASESOR)

## Depth Strategy
**Borders-only** — sin sombras. La jerarquía se define por cambios sutiles en el color de fondo y opacidad de bordes.

## Insets
Los inputs y selects usan `bg-gray-900` (más oscuro que el card `bg-gray-800` que los contiene) para señalizar "escribe aquí" visualmente.

## Spacing Base
`4px` (p-1 = 4px, p-2 = 8px, p-3 = 12px, p-4 = 16px, p-5 = 20px, p-6 = 24px)

## Border Radius
- Inputs/buttons: `rounded-lg` (8px)
- Cards: `rounded-xl` (12px)
- Avatares/indicadores: `rounded-full`

## Key Component Patterns

### Client List Filters
Tabs tipo pill `rounded-full px-3 py-1 text-xs font-medium`. Active: `bg-white text-gray-900`. Inactive: `bg-transparent text-gray-400 hover:text-gray-200`.

### Status Indicator on Select
Colored dot (`absolute left-3 w-2 h-2 rounded-full`) posicionado dentro del wrapper del `<select>`. El select usa `appearance-none` con `pl-6` para espacio del dot. Mapeo de colores por estado (ver Palette > Statuses).

### Message Bubbles
- From me: `self-end bg-gray-700 text-gray-100 rounded-tr-none`
- From them: `self-start bg-gray-800 border border-gray-700 text-gray-200 rounded-tl-none`
- Max width: `max-w-[65%]`
- Timestamp: `text-[10px]` absolute bottom-right

### Action Bar Buttons
- Crear Venta: `bg-white text-gray-900` (primary action)
- Validar Pago: `bg-gray-700 text-gray-100` (secondary)
- Venta Cumplida: `bg-emerald-800 text-emerald-100` (success)

### Alert Badges
`rounded-full` con `animate-pulse`, colored bg at 30% opacity (`bg-emerald-900/30`, `bg-red-900/30`, `bg-amber-900/30`), dot `animate-ping`.

### CRM Right Panel Cards
`rounded-xl bg-gray-800 p-4 border border-gray-700/50`. Section headers: `text-sm font-bold uppercase tracking-wider text-gray-100` with Lucide icon.

## When to Reuse
- Any new page: use `bg-gray-900` base, cards at `bg-gray-800`, inputs at `bg-gray-900`
- New selects: use `bg-gray-900 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-gray-200 appearance-none`
- New buttons: prefer `bg-white text-gray-900` for primary actions