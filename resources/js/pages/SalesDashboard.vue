<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

interface Product { name: string }
interface Variant  { color: string; size: string; product: Product }
interface OrderItem { id: number; quantity: number; price: string; variant: Variant }
interface Client { name: string; phone: string }
interface Order {
    id: number; total: string; status: string;
    shipping_address: string; shipping_method: string;
    created_at: string; client: Client; items: OrderItem[]
}
interface FunnelStage { stage: string; count: number }
interface TopProduct  { name: string; sales_count: number; price: number; stock: number }
interface RevenuePoint { day: string; revenue: number; orders: number }
interface HotLead { id: number; name: string | null; phone: string; status: string; lead_score: number; last_interaction_at: string | null }

const props = defineProps<{
    orders: Order[];
    totalSales: number;
    totalOrders: number;
    completedOrders: number;
    pendingOrders: number;
    aov: number;
    repeatRate: number;
    funnel: FunnelStage[];
    conversionRate: number;
    topProducts: TopProduct[];
    revenueSeries: RevenuePoint[];
    firstResponseAvgMinutes: number | null;
    hotLeads: HotLead[];
    lowStockCount: number;
}>();

const maxRevenue = Math.max(...(props.revenueSeries?.map(r => r.revenue) ?? [1]), 1);

function shortDay(day: string) {
    const d = new Date(day);
    return d.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit' });
}

function tfrLabel(min: number | null) {
    if (min == null) return '—';
    if (min < 60) return `${min} min`;
    const h = Math.floor(min / 60);
    const m = Math.round(min % 60);
    return `${h}h ${m}m`;
}

function formatTime(dt: string) {
    if (!dt) return '';
    return new Date(dt).toLocaleDateString('es-PE') + ' ' +
           new Date(dt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatMoney(amount: number | string) {
    return 'S/ ' + parseFloat(amount.toString()).toFixed(2);
}

const funnelMax = Math.max(...(props.funnel?.map(f => f.count) ?? [1]), 1);
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Panel de Ventas', href: '/dashboard' }]">
        <Head title="Panel de Ventas — Roma CRM" />

        <div class="min-h-[calc(100vh-4rem)] bg-[#0b141a] font-sans p-6 text-[#e9edef] overflow-y-auto">
            <div class="mx-auto max-w-7xl space-y-8">

                <!-- Header -->
                <div>
                    <h1 class="text-3xl font-bold text-[#e9edef]">📊 Panel de Ventas</h1>
                    <p class="text-[#8696a0] mt-1">Métricas en tiempo real de Roma Store</p>
                </div>

                <!-- KPI Cards principales -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-[#111b21] rounded-xl p-5 border border-[#202c33]">
                        <p class="text-xs text-[#8696a0] mb-1">Ingresos Totales</p>
                        <p class="text-2xl font-bold text-[#00a884]">{{ formatMoney(totalSales) }}</p>
                    </div>
                    <div class="bg-[#111b21] rounded-xl p-5 border border-[#202c33]">
                        <p class="text-xs text-[#8696a0] mb-1">Ticket Promedio (AOV)</p>
                        <p class="text-2xl font-bold text-[#e9edef]">{{ formatMoney(aov) }}</p>
                    </div>
                    <div class="bg-[#111b21] rounded-xl p-5 border border-[#202c33]">
                        <p class="text-xs text-[#8696a0] mb-1">Conversión</p>
                        <p class="text-2xl font-bold text-[#53bdeb]">{{ conversionRate }}%</p>
                    </div>
                    <div class="bg-[#111b21] rounded-xl p-5 border border-[#202c33]">
                        <p class="text-xs text-[#8696a0] mb-1">Recompra</p>
                        <p class="text-2xl font-bold text-[#e9edef]">{{ repeatRate }}%</p>
                    </div>
                </div>

                <!-- KPI Cards secundarios -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-[#111b21] rounded-xl p-5 border border-[#202c33]">
                        <p class="text-xs text-[#8696a0] mb-1">Total Pedidos</p>
                        <p class="text-xl font-bold text-[#e9edef]">{{ totalOrders }}</p>
                    </div>
                    <div class="bg-[#111b21] rounded-xl p-5 border border-[#202c33]">
                        <p class="text-xs text-[#8696a0] mb-1">Completados</p>
                        <p class="text-xl font-bold text-[#00a884]">{{ completedOrders }}</p>
                    </div>
                    <div class="bg-[#111b21] rounded-xl p-5 border border-[#202c33]">
                        <p class="text-xs text-[#8696a0] mb-1">Pendientes</p>
                        <p class="text-xl font-bold text-[#f15c6d]">{{ pendingOrders }}</p>
                    </div>
                    <div class="bg-[#111b21] rounded-xl p-5 border border-[#202c33]">
                        <p class="text-xs text-[#8696a0] mb-1">Tiempo 1º Respuesta</p>
                        <p class="text-xl font-bold"
                           :class="firstResponseAvgMinutes != null && firstResponseAvgMinutes <= 5 ? 'text-[#00a884]' : 'text-[#f5b400]'">
                            {{ tfrLabel(firstResponseAvgMinutes) }}
                        </p>
                    </div>
                </div>

                <!-- Alertas -->
                <div v-if="lowStockCount > 0"
                     class="bg-[#5c1621]/40 border border-[#f15c6d]/40 rounded-xl p-4 flex items-center justify-between">
                    <p class="text-sm text-[#f15c6d]">⚠️ <b>{{ lowStockCount }}</b> variantes con stock crítico (menos de 3 uds).</p>
                    <a href="/inventory" class="text-xs font-semibold text-[#f15c6d] underline">Reponer ahora</a>
                </div>

                <!-- Revenue chart -->
                <div class="bg-[#111b21] rounded-xl border border-[#202c33] p-6">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-lg font-semibold text-[#e9edef]">📈 Ingresos últimos 14 días</h2>
                        <span class="text-xs text-[#8696a0]">Máx: {{ formatMoney(maxRevenue) }}</span>
                    </div>
                    <div class="flex items-end gap-1 h-40">
                        <div v-for="(point, i) in revenueSeries" :key="i"
                             class="flex-1 flex flex-col items-center gap-1 group">
                            <div class="w-full bg-[#00a884]/80 hover:bg-[#00a884] rounded-t transition-all"
                                 :style="{ height: maxRevenue > 0 ? (point.revenue / maxRevenue * 100) + '%' : '0%', minHeight: point.revenue > 0 ? '4px' : '0' }"
                                 :title="`${shortDay(point.day)}: ${formatMoney(point.revenue)} (${point.orders} ped.)`">
                            </div>
                            <span class="text-[10px] text-[#8696a0]">{{ shortDay(point.day) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Funnel + Top Products -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Conversion Funnel -->
                    <div class="bg-[#111b21] rounded-xl border border-[#202c33] p-6">
                        <h2 class="text-lg font-semibold text-[#e9edef] mb-5">🔻 Embudo de Conversión</h2>
                        <div class="space-y-3">
                            <div v-for="(stage, idx) in funnel" :key="idx">
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-[#e9edef]">{{ stage.stage }}</span>
                                    <span class="text-[#8696a0] font-mono">{{ stage.count }}</span>
                                </div>
                                <div class="w-full bg-[#202c33] rounded-full h-3 overflow-hidden">
                                    <div
                                        class="h-3 rounded-full transition-all duration-500"
                                        :style="{
                                            width: funnelMax > 0 ? Math.round((stage.count / funnelMax) * 100) + '%' : '0%',
                                            background: `hsl(${160 - idx * 22}, 60%, 45%)`
                                        }"
                                    ></div>
                                </div>
                            </div>
                            <p v-if="!funnel || funnel.length === 0" class="text-[#8696a0] text-sm">Sin datos de clientes aún.</p>
                        </div>
                    </div>

                    <!-- Top Selling Products -->
                    <div class="bg-[#111b21] rounded-xl border border-[#202c33] p-6">
                        <h2 class="text-lg font-semibold text-[#e9edef] mb-5">🏆 Productos Más Vendidos</h2>
                        <div class="space-y-3">
                            <div v-for="(product, idx) in topProducts" :key="idx"
                                 class="flex items-center justify-between py-2 border-b border-[#202c33] last:border-0">
                                <div class="flex items-center gap-3">
                                    <span class="text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center"
                                          :class="idx === 0 ? 'bg-[#d4af37] text-black' : idx === 1 ? 'bg-[#b0b0b0] text-black' : idx === 2 ? 'bg-[#cd7f32] text-black' : 'bg-[#202c33] text-[#8696a0]'">
                                        {{ idx + 1 }}
                                    </span>
                                    <div>
                                        <p class="text-sm font-medium text-[#e9edef]">{{ product.name }}</p>
                                        <p class="text-xs text-[#8696a0]">S/ {{ product.price }} · Stock: {{ product.stock }}</p>
                                    </div>
                                </div>
                                <span class="text-[#00a884] font-bold text-sm">{{ product.sales_count }} ventas</span>
                            </div>
                            <p v-if="!topProducts || topProducts.length === 0" class="text-[#8696a0] text-sm">Sin ventas registradas aún.</p>
                        </div>
                    </div>
                </div>

                <!-- Leads Calientes -->
                <div v-if="hotLeads && hotLeads.length > 0" class="bg-[#111b21] rounded-xl border border-[#202c33] p-6">
                    <h2 class="text-lg font-semibold text-[#e9edef] mb-5">🔥 Leads Calientes (score ≥ 70)</h2>
                    <div class="space-y-2">
                        <a v-for="lead in hotLeads" :key="lead.id" :href="`/crm/chat/${lead.id}`"
                           class="flex items-center justify-between py-2 px-3 rounded-lg hover:bg-[#202c33]/60 transition-colors border-b border-[#202c33] last:border-0">
                            <div>
                                <p class="text-sm font-medium text-[#e9edef]">{{ lead.name || lead.phone }}</p>
                                <p class="text-xs text-[#8696a0]">{{ lead.status }} — {{ lead.phone }}</p>
                            </div>
                            <span class="text-[#f5b400] font-bold text-sm">🔥 {{ lead.lead_score }}</span>
                        </a>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="bg-[#111b21] rounded-xl border border-[#202c33] overflow-hidden">
                    <div class="px-6 py-4 border-b border-[#202c33] flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-[#e9edef]">📋 Registro de Pedidos</h2>
                        <span class="text-xs text-[#8696a0]">{{ orders.length }} pedidos en total</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-[#202c33]/60 text-[#8696a0] text-xs uppercase tracking-wide">
                                <tr>
                                    <th class="px-5 py-3">ID</th>
                                    <th class="px-5 py-3">Cliente</th>
                                    <th class="px-5 py-3">Artículos</th>
                                    <th class="px-5 py-3">Total</th>
                                    <th class="px-5 py-3">Estado</th>
                                    <th class="px-5 py-3">Método Envío</th>
                                    <th class="px-5 py-3">Fecha</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#202c33]">
                                <tr v-for="order in orders" :key="order.id"
                                    class="hover:bg-[#202c33]/40 transition-colors">
                                    <td class="px-5 py-4 font-mono text-[#53bdeb] text-xs">#{{ String(order.id).padStart(5, '0') }}</td>
                                    <td class="px-5 py-4">
                                        <p class="font-medium text-[#e9edef]">{{ order.client?.name || 'Desconocido' }}</p>
                                        <p class="text-xs text-[#8696a0]">{{ order.client?.phone }}</p>
                                    </td>
                                    <td class="px-5 py-4 text-xs text-[#8696a0] max-w-[200px]">
                                        <div v-for="item in order.items" :key="item.id">
                                            {{ item.quantity }}× {{ item.variant?.product?.name }}
                                            <span v-if="item.variant"> ({{ item.variant.color }}, {{ item.variant.size }})</span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 font-semibold text-[#00a884]">{{ formatMoney(order.total) }}</td>
                                    <td class="px-5 py-4">
                                        <span class="px-2.5 py-1 text-xs font-semibold rounded-full"
                                              :class="{
                                                  'bg-[#005c4b]/60 text-[#00a884]': ['PAGADO','ENTREGADO','COMPLETADA'].includes(order.status),
                                                  'bg-[#5c1621]/60 text-[#f15c6d]': order.status === 'PENDIENTE',
                                                  'bg-[#1a3a5c]/60 text-[#53bdeb]': order.status === 'ENVIADO',
                                                  'bg-[#202c33] text-[#8696a0]': !['PAGADO','ENTREGADO','COMPLETADA','PENDIENTE','ENVIADO'].includes(order.status),
                                              }">
                                            {{ order.status }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-xs text-[#8696a0]">{{ order.shipping_method }}</td>
                                    <td class="px-5 py-4 text-xs text-[#8696a0]">{{ formatTime(order.created_at) }}</td>
                                </tr>
                                <tr v-if="orders.length === 0">
                                    <td colspan="7" class="px-6 py-10 text-center text-[#8696a0]">
                                        No hay pedidos registrados aún.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
