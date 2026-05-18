<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import echo from '@/echo';

interface Tag { id: number; name: string; color: string }
interface AssignedUser { id: number; name: string }
interface ClientNote {
    id: number;
    body: string;
    created_at: string;
    user?: { id: number; name: string } | null;
}

interface Client {
    id: number;
    name: string;
    phone: string;
    status: string;
    priority: 'ALTA' | 'MEDIA' | 'BAJA';
    last_interaction_at: string;
    assigned_user_id?: number | null;
    assigned_user?: AssignedUser | null;
    lead_score?: number;
    tags?: Tag[];
}

interface QuickReply {
    id: number;
    shortcut: string;
    title: string;
    body: string;
}

interface User { id: number; name: string }

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
    allTags?: Tag[];
    quickReplies?: QuickReply[];
    users?: User[];
    clientNotes?: ClientNote[];
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
const noteInput = ref('');
const newTagName = ref('');
const newTagColor = ref('#00a884');
const showQuickReplies = ref(false);
const activeTab = ref('todos');

// ── Send manual WhatsApp message from CRM ────────────────────────────────────
function sendManualMessage() {
    const body = messageInput.value.trim();
    if (!body || !props.selectedClient) return;
    router.post(route('crm.client.send', props.selectedClient.id), { body }, {
        preserveScroll: true,
        onSuccess: () => { messageInput.value = ''; showQuickReplies.value = false; },
        onError: (errs) => { alert(errs.body || errs.message || 'No se pudo enviar.'); },
    });
}

function onMessageInputKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendManualMessage();
    } else if (e.key === 'Escape') {
        showQuickReplies.value = false;
    }
}

watch(messageInput, (val) => {
    showQuickReplies.value = val.trim().startsWith('/') && val.length >= 1;
});

const filteredQuickReplies = computed(() => {
    const q = messageInput.value.trim().toLowerCase();
    if (!q.startsWith('/')) return [];
    const needle = q.slice(1);
    return (props.quickReplies || []).filter(qr =>
        qr.shortcut.toLowerCase().includes(needle) ||
        qr.title.toLowerCase().includes(needle)
    );
});

function applyQuickReply(qr: QuickReply) {
    messageInput.value = qr.body;
    showQuickReplies.value = false;
    nextTick(() => {
        const el = document.getElementById('crm-message-input') as HTMLInputElement | null;
        el?.focus();
    });
}

// ── Internal notes ───────────────────────────────────────────────────────────
function addNote() {
    const body = noteInput.value.trim();
    if (!body || !props.selectedClient) return;
    router.post(route('crm.notes.store', props.selectedClient.id), { body }, {
        preserveScroll: true,
        onSuccess: () => { noteInput.value = ''; },
    });
}

function deleteNote(id: number) {
    if (!confirm('¿Eliminar esta nota?')) return;
    router.delete(route('crm.notes.destroy', id), { preserveScroll: true });
}

// ── Tags ────────────────────────────────────────────────────────────────────
function toggleTag(tagId: number) {
    if (!props.selectedClient) return;
    router.post(route('crm.client.tags.toggle', props.selectedClient.id), { tag_id: tagId }, {
        preserveScroll: true,
    });
}

function createTag() {
    const name = newTagName.value.trim();
    if (!name) return;
    router.post(route('tags.store'), { name, color: newTagColor.value }, {
        preserveScroll: true,
        onSuccess: () => { newTagName.value = ''; },
        onError: (errs) => { alert(errs.name || 'No se pudo crear la etiqueta.'); },
    });
}

function clientHasTag(tagId: number) {
    return props.selectedClient?.tags?.some(t => t.id === tagId) ?? false;
}

// ── Assignment ──────────────────────────────────────────────────────────────
function assignUser(userId: number | null) {
    if (!props.selectedClient) return;
    router.post(route('crm.client.assign', props.selectedClient.id), { user_id: userId }, {
        preserveScroll: true,
    });
}

// ── Payment verification ────────────────────────────────────────────────────────
function approvePayment() {
    if (!props.selectedClient) return;
    router.post(route('crm.client.payment.approve', props.selectedClient.id), {}, {
        preserveScroll: true,
    });
}

function rejectPayment() {
    if (!props.selectedClient) return;
    if (!confirm('¿Rechazar este pago? Se solicitará nuevo comprobante al cliente.')) return;
    router.post(route('crm.client.payment.reject', props.selectedClient.id), {}, {
        preserveScroll: true,
    });
}

function formatDateTime(dt: string) {
    if (!dt) return '';
    const d = new Date(dt);
    return d.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit' }) +
        ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

const statusColors: Record<string, string> = {
    'NUEVO': 'bg-blue-500',
    'INTERESADO': 'bg-yellow-500',
    'CONSULTANDO': 'bg-orange-500',
    'ESPERANDO PAGO': 'bg-purple-500',
    'VERIFICARYAPE': 'bg-amber-500',
    'PAGO RECIBIDO': 'bg-green-500',
    'NECESITA ASESOR': 'bg-red-500',
};

const priorityIcons: Record<string, string> = {
    'ALTA': '🔴',
    'MEDIA': '🟡',
    'BAJA': '⚪',
};

const filteredClients = computed(() =>
    (props.clients || []).filter(c => {
        const matchesSearch = c.name?.toLowerCase().includes(search.value.toLowerCase()) || c.phone.includes(search.value);
        
        if (!matchesSearch) return false;
        
        if (activeTab.value === 'todos') return true;
        if (activeTab.value === 'nuevos') return c.status === 'NUEVO';
        if (activeTab.value === 'por_responder') {
            const lastMsgTime = c.last_customer_message_at ? new Date(c.last_customer_message_at).getTime() : 0;
            const now = Date.now();
            const hoursSinceLastMsg = (now - lastMsgTime) / (1000 * 60 * 60);
            return hoursSinceLastMsg > 1 && !['FINALIZADO', 'VENTA CUMPLIDA', 'ABANDONADO'].includes(c.status);
        }
        if (activeTab.value === 'esperando_pago') return c.status === 'ESPERANDO PAGO';
        if (activeTab.value === 'verificaryape') return c.status === 'VERIFICARYAPE';
        if (activeTab.value === 'necesita_asesor') return c.status === 'NECESITA ASESOR';
        
        return true;
    })
);

const needsAttentionCount = computed(() =>
    (props.clients || []).filter(c => c.status === 'NECESITA ASESOR').length
);

const paymentReceivedCount = computed(() =>
    (props.clients || []).filter(c => c.status === 'PAGO RECIBIDO').length
);

const verifyYapeCount = computed(() =>
    (props.clients || []).filter(c => c.status === 'VERIFICARYAPE').length
);

// ── Audio alerts: 1-shot on status transition ─────────────────────────────
// Files live in /public/Audios/. Names contain spaces, so URL-encode.
const asesorAudio = typeof Audio !== 'undefined'
    ? new Audio('/Audios/' + encodeURIComponent('NECESITA ASESOR.mp3'))
    : null;
const yapeAudio = typeof Audio !== 'undefined'
    ? new Audio('/Audios/VERIFICARYAPE.mp3')
    : null;
if (asesorAudio) asesorAudio.preload = 'auto';
if (yapeAudio) yapeAudio.preload = 'auto';

function playAlert(audio: HTMLAudioElement | null) {
    if (!audio) return;
    try {
        audio.currentTime = 0;
        audio.play().catch(() => {/* autoplay blocked — user must interact first */});
    } catch (_) {/* noop */}
}

// ── Real-time via Laravel Reverb ───────────────────────────────────────────
const localMessages = ref<Message[]>(props.messages || []);
watch(() => props.messages, (val) => { localMessages.value = val || []; }, { deep: true });

function listenToClient(clientId: number) {
    return echo
        .private(`client.${clientId}`)
        .listen('.message.received', (e: any) => {
            if (!e?.message) return;
            localMessages.value.push(e.message);
            nextTick(() => scrollToBottom());
        });
}

let dashboardChannel: any = null;

onMounted(() => {
    // Listen to CRM dashboard for status / priority changes on any client
    dashboardChannel = echo
        .private('crm-dashboard')
        .listen('.client.updated', (e: any) => {
            if (!e?.client) return;
            const idx = (props.clients || []).findIndex((c: Client) => c.id === e.client.id);
            const prevStatus = idx !== -1 ? props.clients[idx].status : null;
            const newStatus = e.client.status;

            if (idx !== -1) {
                Object.assign(props.clients[idx], e.client);
            } else {
                // New client appearing on the dashboard
                (props.clients || []).unshift(e.client as Client);
            }

            // Audio alerts only on actual transition into the alert status
            if (newStatus !== prevStatus) {
                if (newStatus === 'NECESITA ASESOR') playAlert(asesorAudio);
                else if (newStatus === 'PAGO RECIBIDO') playAlert(yapeAudio);
            }
        });

    // If a client is already selected, listen to its channel
    if (props.selectedClient) {
        listenToClient(props.selectedClient.id);
    }
});

onUnmounted(() => {
    if (dashboardChannel) {
        echo.private('crm-dashboard').stopListening('.client.updated');
    }
});

// Watch selectedClient changes to subscribe/unsubscribe
let activeClientChannel: any = null;
watch(() => props.selectedClient, (client) => {
    if (activeClientChannel) {
        echo.leave(`client.${activeClientChannel}`);
        activeClientChannel = null;
    }
    if (client) {
        listenToClient(client.id);
        activeClientChannel = client.id;
    }
});

function scrollToBottom() {
    const el = document.getElementById('chat-messages-container');
    if (el) el.scrollTop = el.scrollHeight;
}

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
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <div v-if="paymentReceivedCount > 0" class="flex items-center gap-2 rounded-full bg-[#22c55e]/20 px-3 py-1 animate-pulse">
                        <span class="relative flex h-3 w-3">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#22c55e] opacity-75"></span>
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-[#22c55e]"></span>
                        </span>
                        <span class="text-xs font-bold text-[#22c55e]">💰 {{ paymentReceivedCount }} pago{{ paymentReceivedCount > 1 ? 's' : '' }} por validar</span>
                    </div>
                    <div v-if="needsAttentionCount > 0" class="flex items-center gap-2 rounded-full bg-[#ef4444]/20 px-3 py-1 animate-pulse">
                        <span class="relative flex h-3 w-3">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#ef4444] opacity-75"></span>
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-[#ef4444]"></span>
                        </span>
                        <span class="text-xs font-bold text-[#ef4444]">{{ needsAttentionCount }} necesitan atención</span>
                    </div>
                    <div v-if="verifyYapeCount > 0" class="flex items-center gap-2 rounded-full bg-[#f59e0b]/20 px-3 py-1 animate-pulse">
                        <span class="relative flex h-3 w-3">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#f59e0b] opacity-75"></span>
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-[#f59e0b]"></span>
                        </span>
                        <span class="text-xs font-bold text-[#f59e0b]">{{ verifyYapeCount }} comprobante{{ verifyYapeCount > 1 ? 's' : '' }} por revisar</span>
                    </div>
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

            <!-- Filter Tabs -->
            <div class="flex gap-1 px-3 py-2 overflow-x-auto">
                <button
                    @click="activeTab = 'todos'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'todos' ? 'bg-[#00a884] text-[#111b21]' : 'bg-transparent text-[#8696a0] hover:text-[#e9edef]'"
                >
                    Todos
                </button>
                <button
                    @click="activeTab = 'nuevos'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'nuevos' ? 'bg-[#00a884] text-[#111b21]' : 'bg-transparent text-[#8696a0] hover:text-[#e9edef]'"
                >
                    Nuevos
                </button>
                <button
                    @click="activeTab = 'por_responder'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'por_responder' ? 'bg-[#00a884] text-[#111b21]' : 'bg-transparent text-[#8696a0] hover:text-[#e9edef]'"
                >
                    Por responder
                </button>
                <button
                    @click="activeTab = 'esperando_pago'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'esperando_pago' ? 'bg-[#00a884] text-[#111b21]' : 'bg-transparent text-[#8696a0] hover:text-[#e9edef]'"
                >
                    Esperando pago
                </button>
                <button
                    @click="activeTab = 'verificaryape'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'verificaryape' ? 'bg-[#00a884] text-[#111b21]' : 'bg-transparent text-[#8696a0] hover:text-[#e9edef]'"
                >
                    📸 Verificar Yape
                </button>
                <button
                    @click="activeTab = 'necesita_asesor'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'necesita_asesor' ? 'bg-[#00a884] text-[#111b21]' : 'bg-transparent text-[#8696a0] hover:text-[#e9edef]'"
                >
                    Necesita asesor
                </button>
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
                        <span v-if="client.status === 'NECESITA ASESOR'" class="absolute -right-0.5 -top-0.5 flex h-4 w-4">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#ef4444] opacity-75"></span>
                            <span class="relative inline-flex h-4 w-4 rounded-full bg-[#ef4444]"></span>
                        </span>
                        <span v-else-if="client.status === 'PAGO RECIBIDO'" class="absolute -right-0.5 -top-0.5 flex h-4 w-4">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#22c55e] opacity-75"></span>
                            <span class="relative inline-flex h-4 w-4 rounded-full bg-[#22c55e]"></span>
                        </span>
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
                        <div v-if="client.tags && client.tags.length > 0" class="mt-1 flex flex-wrap gap-1">
                            <span v-for="t in client.tags.slice(0, 3)" :key="t.id"
                                  :style="{ backgroundColor: t.color }"
                                  class="rounded-full px-1.5 text-[9px] font-bold text-black">
                                {{ t.name }}
                            </span>
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
                        <div v-if="selectedClient.status === 'NECESITA ASESOR'" class="ml-4 flex items-center gap-2 rounded-full bg-[#ef4444]/20 px-3 py-1 animate-pulse">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#ef4444] opacity-75"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-[#ef4444]"></span>
                            </span>
                            <span class="text-xs font-bold text-[#ef4444]">NECESITA ASESOR</span>
                        </div>
                        <div v-else-if="selectedClient.status === 'PAGO RECIBIDO'" class="ml-4 flex items-center gap-2 rounded-full bg-[#22c55e]/20 px-3 py-1 animate-pulse">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#22c55e] opacity-75"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-[#22c55e]"></span>
                            </span>
                            <span class="text-xs font-bold text-[#22c55e]">💰 PAGO RECIBIDO — Validar</span>
                        </div>
                        <div v-else-if="selectedClient.status === 'VERIFICARYAPE'" class="ml-4 flex items-center gap-2 rounded-full bg-[#f59e0b]/20 px-3 py-1 animate-pulse">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#f59e0b] opacity-75"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-[#f59e0b]"></span>
                            </span>
                            <span class="text-xs font-bold text-[#f59e0b]">📸 VERIFICARYAPE — Revisar comprobante</span>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <div id="chat-messages-container" class="z-10 flex flex-1 flex-col gap-2 overflow-y-auto p-6">
                    <div
                        v-for="msg in localMessages"
                        :key="msg.id"
                        class="relative max-w-[65%] rounded-lg p-2 text-sm shadow-sm"
                        :class="msg.from_me ? 'self-end rounded-tr-none bg-[#005c4b]' : 'self-start rounded-tl-none bg-[#202c33]'"
                    >
                        <p class="pr-12">{{ msg.body }}</p>
                        <span class="absolute bottom-1 right-2 flex items-center text-[10px] text-[#8696a0]">
                            {{ formatTime(msg.created_at) }}
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

                <!-- Input + Quick Replies dropdown -->
                <div class="relative z-10 bg-[#202c33] px-4 py-2">
                    <!-- Quick replies suggester -->
                    <div v-if="showQuickReplies && filteredQuickReplies.length > 0"
                         class="absolute bottom-full left-4 right-4 mb-2 max-h-64 overflow-y-auto rounded-xl border border-[#2a3942] bg-[#111b21] shadow-2xl">
                        <div v-for="qr in filteredQuickReplies" :key="qr.id"
                             @click="applyQuickReply(qr)"
                             class="cursor-pointer border-b border-[#2a3942] px-4 py-3 hover:bg-[#202c33] last:border-0">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="font-mono text-xs text-[#00a884]">{{ qr.shortcut }}</span>
                                <span class="text-xs font-semibold text-[#e9edef]">{{ qr.title }}</span>
                            </div>
                            <p class="mt-1 line-clamp-2 text-xs text-[#8696a0]">{{ qr.body }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <input
                            id="crm-message-input"
                            v-model="messageInput"
                            @keydown="onMessageInputKeydown"
                            type="text"
                            placeholder="Escribe un mensaje aquí (usa / para atajos)"
                            class="flex-1 rounded-lg border-none bg-[#2a3942] px-4 py-2 text-sm placeholder:text-[#8696a0] focus:ring-0"
                        />
                        <button
                            @click="sendManualMessage"
                            :disabled="!messageInput.trim()"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-[#00a884] text-black transition-all hover:bg-[#06cf9c] disabled:cursor-not-allowed disabled:opacity-40"
                            title="Enviar"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                            </svg>
                        </button>
                    </div>
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
                    <p v-if="selectedClient.lead_score && selectedClient.lead_score >= 70"
                       class="mt-2 inline-flex items-center gap-1 rounded-full bg-[#f5b400]/20 px-2 py-0.5 text-xs font-bold text-[#f5b400]">
                        🔥 Lead caliente — score {{ selectedClient.lead_score }}
                    </p>

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
                            <option value="VERIFICARYAPE">🟠 VERIFICARYAPE</option>
                            <option value="PAGO RECIBIDO">🟢 PAGO RECIBIDO</option>
                            <option value="VENTA CUMPLIDA">🟢 VENTA CUMPLIDA</option>
                            <option value="NECESITA ASESOR">🔴 NECESITA ASESOR</option>
                        </select>
                    </div>

                    <!-- Assigned to -->
                    <div class="mt-3">
                        <label class="block text-xs font-semibold text-[#8696a0] text-left mb-1.5">Asignado a</label>
                        <select
                            :value="selectedClient.assigned_user_id ?? ''"
                            @change="assignUser(($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)"
                            class="w-full rounded-lg bg-[#2a3942] border border-[#374045] py-1.5 px-3 text-sm text-[#e9edef] focus:ring-[#00a884] focus:border-[#00a884]"
                        >
                            <option value="">— Sin asignar —</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>

                    <!-- Payment Receipt Verification -->
                    <div v-if="selectedClient.status === 'VERIFICARYAPE' && selectedClient.payment_receipt_url" class="mt-4 rounded-xl bg-[#f59e0b]/10 border border-[#f59e0b]/30 p-4">
                        <h4 class="text-sm font-bold text-[#f59e0b] mb-3">📸 Comprobante de Pago</h4>
                        <div class="mb-3">
                            <img :src="selectedClient.payment_receipt_url" alt="Comprobante" class="mx-auto max-h-48 rounded-lg border border-[#374045]" />
                        </div>
                        <div v-if="selectedClient.paid_amount" class="mb-3 text-sm">
                            <span class="text-[#8696a0]">Monto detectado:</span>
                            <span class="ml-2 font-bold text-[#e9edef]">S/ {{ selectedClient.paid_amount.toFixed(2) }}</span>
                        </div>
                        <div class="flex gap-2">
                            <button @click="approvePayment" class="flex-1 rounded-lg bg-[#22c55e] px-3 py-2 text-sm font-bold text-[#111b21] hover:bg-[#16a34a]">
                                ✅ Aprobar
                            </button>
                            <button @click="rejectPayment" class="flex-1 rounded-lg bg-[#ef4444] px-3 py-2 text-sm font-bold text-[#e9edef] hover:bg-[#dc2626]">
                                ❌ Rechazar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tags -->
                <div class="mb-5 rounded-xl bg-[#202c33] p-4 border border-[#2a3942]">
                    <h4 class="text-sm font-bold uppercase tracking-wider text-[#00a884] mb-3">🏷️ Etiquetas</h4>

                    <div class="flex flex-wrap gap-1.5 mb-3">
                        <button
                            v-for="tag in allTags" :key="tag.id"
                            @click="toggleTag(tag.id)"
                            :style="{ borderColor: tag.color, color: clientHasTag(tag.id) ? '#0b141a' : tag.color, backgroundColor: clientHasTag(tag.id) ? tag.color : 'transparent' }"
                            class="rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-all hover:opacity-80"
                        >
                            {{ clientHasTag(tag.id) ? '✓ ' : '' }}{{ tag.name }}
                        </button>
                        <p v-if="!allTags || allTags.length === 0" class="text-xs text-[#8696a0]">Sin etiquetas creadas todavía.</p>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <input v-model="newTagName" type="text" placeholder="Nueva etiqueta"
                               class="flex-1 rounded-md bg-[#2a3942] border border-[#374045] py-1 px-2 text-xs text-[#e9edef] focus:ring-[#00a884] focus:border-[#00a884]" />
                        <input v-model="newTagColor" type="color"
                               class="h-7 w-7 rounded cursor-pointer bg-transparent border border-[#374045]" />
                        <button @click="createTag" class="rounded-md bg-[#00a884] px-2 py-1 text-xs font-bold text-black hover:bg-[#06cf9c]">+</button>
                    </div>
                </div>

                <!-- Internal Notes -->
                <div class="mb-5 rounded-xl bg-[#202c33] p-4 border border-[#2a3942]">
                    <h4 class="text-sm font-bold uppercase tracking-wider text-[#00a884] mb-3">📝 Notas internas</h4>

                    <div class="flex gap-2 mb-3">
                        <textarea v-model="noteInput" rows="2" placeholder="Escribe una nota privada (solo el equipo la ve)"
                                  class="flex-1 rounded-md bg-[#2a3942] border border-[#374045] py-1.5 px-2 text-xs text-[#e9edef] focus:ring-[#00a884] focus:border-[#00a884] resize-none"></textarea>
                        <button @click="addNote" :disabled="!noteInput.trim()"
                                class="self-start rounded-md bg-[#00a884] px-3 py-1.5 text-xs font-bold text-black hover:bg-[#06cf9c] disabled:opacity-40">
                            Guardar
                        </button>
                    </div>

                    <div v-if="clientNotes && clientNotes.length > 0" class="space-y-2 max-h-60 overflow-y-auto pr-1">
                        <div v-for="n in clientNotes" :key="n.id"
                             class="group rounded-md p-2 text-xs border"
                             :class="n.user ? 'bg-[#2a3942]/60 border-[#374045]' : 'bg-[#1a3a5c]/30 border-[#53bdeb]/40'">
                            <div class="flex items-start justify-between gap-2">
                                <p class="whitespace-pre-wrap text-[#e9edef]">{{ n.body }}</p>
                                <button @click="deleteNote(n.id)"
                                        class="opacity-0 group-hover:opacity-100 text-[#f15c6d] text-[10px] transition">
                                    ✕
                                </button>
                            </div>
                            <p class="mt-1 text-[10px] flex items-center gap-1"
                               :class="n.user ? 'text-[#8696a0]' : 'text-[#53bdeb]'">
                                <span v-if="!n.user" class="font-bold">🤖 IA</span>
                                <span v-else>{{ n.user.name }}</span>
                                · {{ formatDateTime(n.created_at) }}
                            </p>
                        </div>
                    </div>
                    <p v-else class="text-xs text-[#8696a0] italic">Sin notas todavía.</p>
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
