<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Product {
    name: string;
}

interface Variant {
    color: string;
    size: string;
    product: Product;
}

interface OrderItem {
    id: number;
    quantity: number;
    price: string;
    variant: Variant;
}

interface Client {
    name: string;
    phone: string;
}

interface Order {
    id: number;
    total: string;
    status: string;
    shipping_address: string;
    shipping_method: string;
    created_at: string;
    client: Client;
    items: OrderItem[];
}

const props = defineProps<{
    orders: Order[];
    totalSales: number;
    totalOrders: number;
    completedOrders: number;
    pendingOrders: number;
}>();

function formatTime(dt: string) {
    if (!dt) return '';
    return new Date(dt).toLocaleDateString() + ' ' + new Date(dt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatMoney(amount: number | string) {
    return 'S/ ' + parseFloat(amount.toString()).toFixed(2);
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Dashboard de Ventas', href: '/dashboard' }]">
        <Head title="Ventas - WhatsApp CRM" />

        <!-- Premium Dark Design applied to the entire workspace -->
        <div class="min-h-[calc(100vh-4rem)] bg-[#0b141a] font-sans p-6 text-[#e9edef] overflow-y-auto">
            <div class="mx-auto max-w-7xl">
                
                <div class="mb-8 flex justify-between items-end">
                    <div>
                        <h1 class="text-3xl font-bold tracking-tight text-[#e9edef] mb-2">Panel de Ventas</h1>
                        <p class="text-[#8696a0]">Monitoriza tus ingresos y pedidos generados desde el CRM en tiempo real.</p>
                    </div>
                </div>

                <!-- KPI Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Ingresos Totales -->
                    <div class="bg-[#111b21] rounded-xl p-6 border border-[#202c33] shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 p-4 opacity-10 text-[#00a884]">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <p class="text-sm font-medium text-[#8696a0] mb-1">Ingresos Totales</p>
                        <h2 class="text-3xl font-bold text-[#e9edef]">{{ formatMoney(totalSales) }}</h2>
                    </div>

                    <!-- Pedidos Totales -->
                    <div class="bg-[#111b21] rounded-xl p-6 border border-[#202c33] shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 p-4 opacity-10 text-[#53bdeb]">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>
                        </div>
                        <p class="text-sm font-medium text-[#8696a0] mb-1">Total Pedidos</p>
                        <h2 class="text-3xl font-bold text-[#e9edef]">{{ totalOrders }}</h2>
                    </div>

                    <!-- Pedidos Completados -->
                    <div class="bg-[#111b21] rounded-xl p-6 border border-[#202c33] shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 p-4 opacity-10 text-[#00a884]">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                        </div>
                        <p class="text-sm font-medium text-[#8696a0] mb-1">Completados</p>
                        <h2 class="text-3xl font-bold text-[#00a884]">{{ completedOrders }}</h2>
                    </div>

                    <!-- Pedidos Pendientes -->
                    <div class="bg-[#111b21] rounded-xl p-6 border border-[#202c33] shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 p-4 opacity-10 text-[#f15c6d]">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <p class="text-sm font-medium text-[#8696a0] mb-1">Pendientes / En Progreso</p>
                        <h2 class="text-3xl font-bold text-[#f15c6d]">{{ pendingOrders }}</h2>
                    </div>
                </div>

                <!-- Lista de Últimas Ventas -->
                <div class="bg-[#111b21] rounded-xl border border-[#202c33] overflow-hidden">
                    <div class="px-6 py-5 border-b border-[#202c33]">
                        <h3 class="text-lg font-medium text-[#e9edef]">Registro de Pedidos Recientes</h3>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-[#202c33]/50 text-[#8696a0]">
                                <tr>
                                    <th class="px-6 py-3 font-medium">Pedido ID</th>
                                    <th class="px-6 py-3 font-medium">Cliente</th>
                                    <th class="px-6 py-3 font-medium">Artículos</th>
                                    <th class="px-6 py-3 font-medium">Total</th>
                                    <th class="px-6 py-3 font-medium">Estado</th>
                                    <th class="px-6 py-3 font-medium">Fecha</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#202c33]">
                                <tr v-for="order in orders" :key="order.id" class="hover:bg-[#202c33]/30 transition-colors">
                                    <td class="px-6 py-4 font-mono text-[#53bdeb]">#{{ String(order.id).padStart(5, '0') }}</td>
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-[#e9edef]">{{ order.client.name || 'Desconocido' }}</div>
                                        <div class="text-xs text-[#8696a0]">{{ order.client.phone }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div v-for="item in order.items" :key="item.id" class="text-xs text-[#8696a0]">
                                            {{ item.quantity }}x {{ item.variant.product.name }} ({{ item.variant.color }}, {{ item.variant.size }})
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 font-semibold text-[#00a884]">{{ formatMoney(order.total) }}</td>
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-1 text-xs font-semibold rounded-full" 
                                              :class="{
                                                  'bg-[#005c4b] text-[#e9edef]': order.status === 'COMPLETADA',
                                                  'bg-[#5c1621] text-[#f15c6d]': order.status === 'PENDIENTE',
                                                  'bg-[#202c33] text-[#8696a0]': order.status !== 'COMPLETADA' && order.status !== 'PENDIENTE'
                                              }">
                                            {{ order.status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-[#8696a0]">{{ formatTime(order.created_at) }}</td>
                                </tr>
                                <tr v-if="orders.length === 0">
                                    <td colspan="6" class="px-6 py-8 text-center text-[#8696a0]">
                                        No hay ventas registradas aún.
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
