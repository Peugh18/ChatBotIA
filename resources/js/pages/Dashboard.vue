<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

interface Client {
    id: number;
    name: string;
    phone: string;
    status: string;
    priority: 'ALTA' | 'MEDIA' | 'BAJA';
    last_interaction_at: string;
}

interface Message {
    id: number;
    body: string;
    from_me: boolean;
    created_at: string;
}

interface ProductVariant {
    id: number;
    color: string;
    size: string;
    stock: number;
}

interface Product {
    id: number;
    name: string;
    sku: string;
    price: number;
    variants: ProductVariant[];
}

interface OrderItem {
    id: number;
    price: string;
    quantity: number;
    variant?: {
        color: string;
        size: string;
        product?: {
            name: string;
        }
    }
}

interface Order {
    id: number;
    total: string;
    status: string;
    shipping_address: string;
    shipping_method: string;
    created_at: string;
    items?: OrderItem[];
}

const props = defineProps<{
    clients: Client[];
    selectedClient?: Client;
    messages?: Message[];
    products?: Product[];
    orders?: Order[];
}>();

const selectedProductId = ref<number | null>(null);
const selectedVariantId = ref<number | null>(null);
const saleQuantity = ref(1);
const saleShippingAddress = ref('');
const saleShippingMethod = ref('Directo');
const saleStatus = ref('COMPLETADA');

const selectedProduct = computed(() => {
    if (!selectedProductId.value || !props.products) return null;
    return props.products.find(p => p.id === selectedProductId.value) || null;
});

const availableVariants = computed(() => {
    return selectedProduct.value ? selectedProduct.value.variants : [];
});

function submitSale() {
    if (!selectedVariantId.value || !props.selectedClient) return;
    
    router.post(route('crm.client.order', props.selectedClient.id), {
        variant_id: selectedVariantId.value,
        quantity: saleQuantity.value,
        status: saleStatus.value,
        shipping_address: saleShippingAddress.value,
        shipping_method: saleShippingMethod.value
    }, {
        onSuccess: () => {
            selectedProductId.value = null;
            selectedVariantId.value = null;
            saleQuantity.value = 1;
            saleShippingAddress.value = '';
            saleShippingMethod.value = 'Directo';
        },
        onError: (errors) => {
            alert(errors.stock || 'Ocurrió un error al registrar la venta.');
        }
    });
}

function updateClientStatus(status: string) {
    if (!props.selectedClient) return;
    router.post(route('crm.client.status', props.selectedClient.id), {
        status: status
    });
}

const search = ref('');
const messageInput = ref('');

const statusColors: Record<string, string> = {
    'NUEVO': 'bg-blue-500',
    'INTERESADO': 'bg-yellow-500',
    'CONSULTANDO': 'bg-orange-500',
    'ESPERANDO PAGO': 'bg-purple-500',
    'PAGO RECIBIDO': 'bg-green-500',
    'NECESITA ASESOR': 'bg-red-500',
};

const priorityIcons: Record<string, string> = {
    'ALTA': '🔴',
    'MEDIA': '🟡',
    'BAJA': '⚪',
};

const filteredClients = computed(() =>
    (props.clients || []).filter(c =>
        (c.name?.toLowerCase().includes(search.value.toLowerCase()) || c.phone.includes(search.value))
    )
);

function formatTime(dt: string) {
    if (!dt) return '';
    return new Date(dt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'CRM / Chatbot', href: '/crm' }]">
        <Head title="WhatsApp CRM Roma" />

        <div class="flex h-[calc(100vh-4rem)] overflow-hidden bg-[#111b21] font-sans text-[#e9edef]">

        <!-- Sidebar -->
        <div class="flex w-[400px] flex-col border-r border-[#222d34] bg-[#111b21]">

            <!-- Header Sidebar -->
            <div class="flex h-[60px] items-center justify-between bg-[#202c33] px-4 py-2">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-[#374045]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-[#e9edef]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A8.966 8.966 0 0112 15c2.34 0 4.47.895 6.056 2.357M12 11a4 4 0 100-8 4 4 0 000 8z" /></svg>
                </div>
            </div>

            <!-- Search -->
            <div class="px-3 py-2">
                <div class="flex items-center rounded-lg bg-[#202c33] px-3 py-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="mr-4 h-4 w-4 text-[#aebac1]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Busca un chat o inicia uno nuevo"
                        class="w-full border-none bg-transparent text-sm placeholder:text-[#8696a0] focus:ring-0"
                    />
                </div>
            </div>

            <!-- Clients List -->
            <div class="flex-1 overflow-y-auto">
                <Link
                    v-for="client in filteredClients"
                    :key="client.id"
                    :href="route('crm.chat', client.id)"
                    class="flex cursor-pointer items-center border-b border-[#222d34] px-3 py-3 transition-colors hover:bg-[#202c33]"
                    :class="selectedClient?.id === client.id ? 'bg-[#2a3942]' : ''"
                >
                    <div class="relative mr-3 flex h-12 w-12 items-center justify-center rounded-full bg-[#374045]">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A8.966 8.966 0 0112 15c2.34 0 4.47.895 6.056 2.357M12 11a4 4 0 100-8 4 4 0 000 8z" /></svg>
                        <span class="absolute bottom-0 right-0 text-[10px]">{{ priorityIcons[client.priority] }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between">
                            <h3 class="truncate text-base font-medium">{{ client.name || client.phone }}</h3>
                            <span class="text-[12px] text-[#8696a0]">{{ formatTime(client.last_interaction_at) }}</span>
                        </div>
                        <div class="mt-0.5 flex items-center justify-between">
                            <p class="flex items-center truncate text-sm text-[#8696a0]">
                                <span class="mr-2 h-2 w-2 rounded-full" :class="statusColors[client.status] || 'bg-gray-500'"></span>
                                {{ client.status }}
                            </p>
                            <div v-if="client.priority === 'ALTA'" class="rounded-full bg-[#00a884] px-1.5 text-[10px] font-bold text-black">
                                NUEVO
                            </div>
                        </div>
                    </div>
                </Link>
            </div>
        </div>

        <!-- Main Chat Area & CRM panel -->
        <div v-if="selectedClient" class="relative flex flex-1 flex-row bg-[#0b141a]">
            
            <!-- Left Chat Pane -->
            <div class="flex flex-1 flex-col bg-[#0b141a] border-r border-[#222d34]">
                <!-- Chat Header -->
                <div class="z-10 flex h-[60px] items-center justify-between bg-[#202c33] px-4 py-2">
                    <div class="flex items-center">
                        <div class="mr-3 flex h-10 w-10 items-center justify-center rounded-full bg-[#374045]">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A8.966 8.966 0 0112 15c2.34 0 4.47.895 6.056 2.357M12 11a4 4 0 100-8 4 4 0 000 8z" /></svg>
                        </div>
                        <div>
                            <h2 class="text-base font-medium leading-tight">{{ selectedClient.name || selectedClient.phone }}</h2>
                            <p class="text-[12px] text-[#8696a0]">{{ selectedClient.status }}</p>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <div class="z-10 flex flex-1 flex-col gap-2 overflow-y-auto p-6">
                    <div
                        v-for="msg in messages"
                        :key="msg.id"
                        class="relative max-w-[65%] rounded-lg p-2 text-sm shadow-sm"
                        :class="msg.from_me ? 'self-end rounded-tr-none bg-[#005c4b]' : 'self-start rounded-tl-none bg-[#202c33]'"
                    >
                        <p class="pr-12">{{ msg.body }}</p>
                        <span class="absolute bottom-1 right-2 flex items-center text-[10px] text-[#8696a0]">
                            {{ formatTime(msg.created_at) }}
                            <svg v-if="msg.from_me" xmlns="http://www.w3.org/2000/svg" class="ml-1 h-3 w-3 text-[#53bdeb]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7M3 13l4 4L17 7" /></svg>
                        </span>
                    </div>
                </div>

                <!-- CRM Action Bar -->
                <div class="z-10 flex gap-4 border-t border-[#222d34] bg-[#202c33]/80 px-4 py-3 backdrop-blur-md">
                    <button 
                        @click="selectedProductId = products && products.length > 0 ? products[0].id : null"
                        class="flex items-center gap-2 rounded-lg bg-[#00a884] px-4 py-1.5 text-sm font-semibold text-black transition-all hover:bg-[#06cf9c]"
                    >
                        🛒 Crear Venta
                    </button>
                    <button 
                        @click="updateClientStatus('PAGO RECIBIDO')"
                        class="flex items-center gap-2 rounded-lg bg-[#3b82f6] px-4 py-1.5 text-sm font-semibold text-white transition-all hover:bg-[#60a5fa]"
                    >
                        💳 Validar Pago
                    </button>
                    <button 
                        @click="updateClientStatus('VENTA CUMPLIDA')"
                        class="flex items-center gap-2 rounded-lg bg-[#10b981] px-4 py-1.5 text-sm font-semibold text-white transition-all hover:bg-[#059669]"
                    >
                        ✅ Venta Cumplida
                    </button>
                </div>

                <!-- Input -->
                <div class="z-10 flex h-[62px] items-center gap-4 bg-[#202c33] px-4 py-2">
                    <input
                        v-model="messageInput"
                        type="text"
                        placeholder="Escribe un mensaje aquí"
                        class="flex-1 rounded-lg border-none bg-[#2a3942] px-4 py-2 text-sm placeholder:text-[#8696a0] focus:ring-0"
                    />
                </div>
            </div>

            <!-- Right Details Pane (CRM Panels) -->
            <div class="w-[380px] flex flex-col bg-[#1c272e] text-[#e9edef] overflow-y-auto p-4 border-l border-[#222d34]">
                <!-- Client Card Header -->
                <div class="mb-5 rounded-xl bg-[#202c33] p-4 text-center border border-[#2a3942]">
                    <div class="mx-auto mb-2 flex h-16 w-16 items-center justify-center rounded-full bg-[#2a3942] ring-2 ring-[#00a884]">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-[#00a884]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                    </div>
                    <h3 class="text-lg font-bold truncate">{{ selectedClient.name || 'Cliente sin nombre' }}</h3>
                    <p class="text-sm text-[#8696a0] mt-0.5">{{ selectedClient.phone }}</p>
                    
                    <!-- Client Status dropdown -->
                    <div class="mt-4">
                        <label class="block text-xs font-semibold text-[#8696a0] text-left mb-1.5">Estado del Cliente</label>
                        <select 
                            :value="selectedClient.status" 
                            @change="updateClientStatus(($event.target as HTMLSelectElement).value)"
                            class="w-full rounded-lg bg-[#2a3942] border border-[#374045] py-1.5 px-3 text-sm text-[#e9edef] focus:ring-[#00a884] focus:border-[#00a884]"
                        >
                            <option value="NUEVO">🔵 NUEVO</option>
                            <option value="INTERESADO">🟡 INTERESADO</option>
                            <option value="CONSULTANDO">🟠 CONSULTANDO</option>
                            <option value="ESPERANDO PAGO">🟣 ESPERANDO PAGO</option>
                            <option value="PAGO RECIBIDO">🟢 PAGO RECIBIDO</option>
                            <option value="VENTA CUMPLIDA">🟢 VENTA CUMPLIDA</option>
                            <option value="NECESITA ASESOR">🔴 NECESITA ASESOR</option>
                        </select>
                    </div>
                </div>

                <!-- Create Direct Order (Venta Cumplida & Deduct Stock) -->
                <div class="mb-5 rounded-xl bg-[#202c33] p-4 border border-[#2a3942]">
                    <h4 class="text-sm font-bold uppercase tracking-wider text-[#00a884] mb-3 flex items-center gap-1.5">
                        🛍️ Registrar Venta
                    </h4>
                    
                    <form @submit.prevent="submitSale" class="space-y-3.5">
                        <!-- Product Selection -->
                        <div>
                            <label class="block text-xs text-[#8696a0] mb-1">Producto</label>
                            <select 
                                v-model="selectedProductId"
                                class="w-full rounded-lg bg-[#2a3942] border border-[#374045] py-1.5 px-3 text-sm text-[#e9edef] focus:ring-[#00a884] focus:border-[#00a884]"
                            >
                                <option :value="null">-- Seleccionar Producto --</option>
                                <option v-for="prod in products" :key="prod.id" :value="prod.id">
                                    {{ prod.name }} (S/ {{ prod.price }})
                                </option>
                            </select>
                        </div>

                        <!-- Variant (Color & Size) Selection -->
                        <div v-if="selectedProductId">
                            <label class="block text-xs text-[#8696a0] mb-1">Variante (Color & Talla)</label>
                            <select 
                                v-model="selectedVariantId"
                                required
                                class="w-full rounded-lg bg-[#2a3942] border border-[#374045] py-1.5 px-3 text-sm text-[#e9edef] focus:ring-[#00a884] focus:border-[#00a884]"
                            >
                                <option :value="null">-- Seleccionar Color & Talla --</option>
                                <option 
                                    v-for="vari in availableVariants" 
                                    :key="vari.id" 
                                    :value="vari.id"
                                    :disabled="vari.stock <= 0"
                                >
                                    {{ vari.color }} - Talla {{ vari.size }} ({{ vari.stock }} disp.)
                                </option>
                            </select>
                        </div>

                        <!-- Quantity -->
                        <div v-if="selectedVariantId" class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs text-[#8696a0] mb-1">Cantidad</label>
                                <input 
                                    v-model.number="saleQuantity"
                                    type="number" 
                                    min="1" 
                                    required
                                    class="w-full rounded-lg bg-[#2a3942] border border-[#374045] py-1.5 px-3 text-sm text-[#e9edef] focus:ring-[#00a884] focus:border-[#00a884]"
                                />
                            </div>
                            <div>
                                <label class="block text-xs text-[#8696a0] mb-1">Método Envío</label>
                                <select 
                                    v-model="saleShippingMethod"
                                    class="w-full rounded-lg bg-[#2a3942] border border-[#374045] py-1.5 px-3 text-sm text-[#e9edef] focus:ring-[#00a884] focus:border-[#00a884]"
                                >
                                    <option value="Directo">Directo</option>
                                    <option value="Motorizado">Motorizado</option>
                                    <option value="Shalom">Shalom</option>
                                </select>
                            </div>
                        </div>

                        <!-- Shipping Address -->
                        <div v-if="selectedVariantId">
                            <label class="block text-xs text-[#8696a0] mb-1">Dirección de Envío</label>
                            <input 
                                v-model="saleShippingAddress"
                                type="text"
                                placeholder="Dirección del cliente"
                                class="w-full rounded-lg bg-[#2a3942] border border-[#374045] py-1.5 px-3 text-sm text-[#e9edef] focus:ring-[#00a884] focus:border-[#00a884]"
                            />
                        </div>

                        <button 
                            v-if="selectedVariantId"
                            type="submit"
                            class="w-full py-2 bg-[#00a884] hover:bg-[#06cf9c] text-black font-bold text-sm rounded-lg transition-colors flex items-center justify-center gap-1.5"
                        >
                            🛍️ Registrar Venta
                        </button>
                    </form>
                </div>

                <!-- Past Orders History -->
                <div class="rounded-xl bg-[#202c33] p-4 border border-[#2a3942] flex-1">
                    <h4 class="text-sm font-bold uppercase tracking-wider text-[#00a884] mb-3">
                        📋 Pedidos Registrados
                    </h4>
                    
                    <div v-if="orders && orders.length > 0" class="space-y-3 max-h-[300px] overflow-y-auto pr-1">
                        <div 
                            v-for="order in orders" 
                            :key="order.id" 
                            class="p-2.5 rounded-lg bg-[#2a3942] border border-[#374045] text-xs"
                        >
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="font-bold text-[#e9edef]">Pedido #{{ order.id }}</span>
                                <span 
                                    class="px-1.5 py-0.5 rounded text-[10px] font-bold"
                                    :class="order.status === 'COMPLETADA' ? 'bg-green-900/60 text-green-400 border border-green-700/50' : 'bg-yellow-900/60 text-yellow-400 border border-yellow-700/50'"
                                >
                                    {{ order.status }}
                                </span>
                            </div>
                            
                            <!-- Items List -->
                            <div v-for="item in order.items" :key="item.id" class="text-[#8696a0] mt-0.5">
                                • {{ item.variant?.product?.name || 'Producto' }} ({{ item.variant?.color }}, {{ item.variant?.size }}) x{{ item.quantity }}
                            </div>

                            <div class="mt-2 pt-1.5 border-t border-[#374045] flex justify-between items-center">
                                <span class="text-[#8696a0] text-[10px]">Envío: {{ order.shipping_method }}</span>
                                <span class="font-bold text-[#00a884]">Total: S/ {{ order.total }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div v-else class="text-center py-6 text-sm text-[#8696a0]">
                        Ningún pedido registrado aún.
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty State -->
        <div v-else class="relative flex flex-1 flex-col items-center justify-center border-b-[6px] border-[#00a884] bg-[#222e35]">
            <div class="w-[300px] text-center">
                <div class="mb-8 flex justify-center opacity-20">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="WA" class="w-24 grayscale" />
                </div>
                <h1 class="mb-3 text-[32px] font-light text-[#e9edef]">WhatsApp CRM Roma</h1>
                <p class="text-sm leading-relaxed text-[#8696a0]">
                    Gestiona tus ventas de TikTok Live de forma automatizada con IA.
                    Selecciona un chat para ver los detalles.
                </p>
            </div>
            <div class="absolute bottom-10 flex items-center gap-2 text-sm text-[#8696a0]">
                ✅ Protegido por Gemini 3 Flash Preview
            </div>
        </div>
        </div>
    </AppLayout>
</template>
