<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import StatusBadge from '@/components/ui/badge/StatusBadge.vue';
import QuickReplyChips from '@/components/QuickReplyChips.vue';
import axios from 'axios';
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { User, Search, DollarSign, X, Plus, Tag, Clock, TrendingUp, Check, AlertCircle, MessageCircle, Package } from 'lucide-vue-next';
import { playIncomingMessageSound } from '@/composables/useIncomingMessageSound';

// shadcn UI components
import Card from '@/components/ui/card/Card.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import Avatar from '@/components/ui/avatar/Avatar.vue';
import AvatarFallback from '@/components/ui/avatar/AvatarFallback.vue';
import { useCrmStore } from '@/stores/crm';
import { storeToRefs } from 'pinia';

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
}const store = useCrmStore();
const {
    clients,
    selectedClient,
    localMessages,
    isSending,
    activeTab,
    search,
    messageInput,
    noteInput,
    newTagName,
    newTagColor,
    showQuickReplies,
    stickToBottom,
} = storeToRefs(store);

const chatContainerRef = ref<HTMLElement | null>(null);
const chatEndRef = ref<HTMLElement | null>(null);

function isNearBottom(): boolean {
    const el = chatContainerRef.value;
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight < 100;
}

function onChatScroll() {
    stickToBottom.value = isNearBottom();
}

function scrollToBottom(force = false) {
    if (!force && !stickToBottom.value) return;
    nextTick(() => {
        requestAnimationFrame(() => {
            chatEndRef.value?.scrollIntoView({ behavior: force ? 'smooth' : 'auto', block: 'end' });
        });
    });
}

function onMessageInputKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        store.sendManualMessage(scrollToBottom);
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
    'NUEVO': 'bg-gray-300',
    'INTERESADO': 'bg-gray-300',
    'CONSULTANDO': 'bg-gray-400',
    'ESPERANDO PAGO': 'bg-gray-500',
    'VERIFICARYAPE': 'bg-gray-600',
    'PAGO RECIBIDO': 'bg-black',
    'NECESITA ASESOR': 'bg-gray-800',
};

const priorityIcons: Record<string, string> = {
    'ALTA': '●',
    'MEDIA': '◐',
    'BAJA': '○',
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

function handleAlert(newStatus: string, prevStatus: string | null) {
    if (newStatus !== prevStatus) {
        if (newStatus === 'NECESITA ASESOR') playAlert(asesorAudio);
        else if (newStatus === 'PAGO RECIBIDO') playAlert(yapeAudio);
    }
}

// ── Real-time via Pusher + Polling ───────────────────────────────────
// Sincronizar store de Pinia con props que Inertia actualiza reactivamente
watch(
    () => props.clients,
    (val) => {
        store.clients = val || [];
    },
    { deep: true, immediate: true }
);

watch(
    () => props.selectedClient,
    (client, prev) => {
        store.selectedClient = client || null;
        if (prev) {
            store.leaveClient(prev.id);
        }
        if (!client) {
            store.stopChatPolling();
            return;
        }

        if (client.id !== prev?.id) {
            store.initKnownMessages(props.messages || []);
            store.stickToBottom = true;
            scrollToBottom(true);
            store.startChatPolling(scrollToBottom);
            store.listenToClient(client.id, scrollToBottom);
        }
    },
    { immediate: true }
);

watch(
    () => props.messages,
    (val) => {
        if (val) store.applyServerMessages(val, false, scrollToBottom);
    },
    { deep: true },
);

onMounted(() => {
    store.initialize(props.clients, props.selectedClient, props.messages);
    store.listenToDashboard(handleAlert);
    if (props.selectedClient) {
        store.stickToBottom = true;
        scrollToBottom(true);
        store.startChatPolling(scrollToBottom);
        store.listenToClient(props.selectedClient.id, scrollToBottom);
    }
});

onUnmounted(() => {
    store.stopChatPolling();
    store.leaveDashboard();
    if (props.selectedClient) {
        store.leaveClient(props.selectedClient.id);
    }
});

function formatTime(dt: string) {
    if (!dt) return '';
    return new Date(dt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'CRM / Chatbot', href: '/crm' }]">
        <Head title="WhatsApp CRM Roma" />

        <div class="flex h-[calc(100vh-4rem)] overflow-hidden bg-background font-sans">

        <!-- Sidebar -->
        <div class="flex w-[400px] flex-col border-r border-border bg-card">

            <!-- Header Sidebar -->
            <div class="flex h-[60px] items-center justify-between bg-muted/50 px-4 py-2 border-b border-border">
                <Avatar>
                    <AvatarFallback><User class="h-5 w-5" /></AvatarFallback>
                </Avatar>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <div v-if="paymentReceivedCount > 0" class="flex items-center gap-2 rounded-full bg-emerald-50 border border-emerald-200 px-3 py-1 animate-pulse">
                        <span class="relative flex h-3 w-3">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-400"></span>
                        </span>
                        <span class="text-xs font-bold text-emerald-700"><DollarSign class="w-4 h-4 inline mr-1" /> {{ paymentReceivedCount }} pago{{ paymentReceivedCount > 1 ? 's' : '' }} por validar</span>
                    </div>
                    <div v-if="needsAttentionCount > 0" class="flex items-center gap-2 rounded-full bg-red-50 border border-red-200 px-3 py-1 animate-pulse">
                        <span class="relative flex h-3 w-3">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-red-400"></span>
                        </span>
                        <span class="text-xs font-bold text-red-700">{{ needsAttentionCount }} necesitan atención</span>
                    </div>
                    <div v-if="verifyYapeCount > 0" class="flex items-center gap-2 rounded-full bg-amber-50 border border-amber-200 px-3 py-1 animate-pulse">
                        <span class="relative flex h-3 w-3">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-amber-400"></span>
                        </span>
                        <span class="text-xs font-bold text-amber-700">{{ verifyYapeCount }} comprobante{{ verifyYapeCount > 1 ? 's' : '' }} por revisar</span>
                    </div>
                </div>
            </div>

            <!-- Search -->
            <div class="px-3 py-2">
                <div class="relative">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Busca un chat o inicia uno nuevo"
                        class="w-full rounded-lg border border-border bg-muted/50 pl-9 pr-3 py-1.5 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="flex gap-1 px-3 py-2 overflow-x-auto">
                <button
                    @click="activeTab = 'todos'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'todos' ? 'bg-primary text-primary-foreground font-semibold shadow-sm' : 'bg-transparent text-muted-foreground hover:text-foreground'"
                >
                    Todos
                </button>
                <button
                    @click="activeTab = 'nuevos'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'nuevos' ? 'bg-primary text-primary-foreground font-semibold shadow-sm' : 'bg-transparent text-muted-foreground hover:text-foreground'"
                >
                    Nuevos
                </button>
                <button
                    @click="activeTab = 'por_responder'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'por_responder' ? 'bg-primary text-primary-foreground font-semibold shadow-sm' : 'bg-transparent text-muted-foreground hover:text-foreground'"
                >
                    Por responder
                </button>
                <button
                    @click="activeTab = 'esperando_pago'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'esperando_pago' ? 'bg-primary text-primary-foreground font-semibold shadow-sm' : 'bg-transparent text-muted-foreground hover:text-foreground'"
                >
                    Esperando pago
                </button>
                <button
                    @click="activeTab = 'verificaryape'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'verificaryape' ? 'bg-primary text-primary-foreground font-semibold shadow-sm' : 'bg-transparent text-muted-foreground hover:text-foreground'"
                >
                    Verificar Yape
                </button>
                <button
                    @click="activeTab = 'necesita_asesor'"
                    class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-medium transition-colors"
                    :class="activeTab === 'necesita_asesor' ? 'bg-primary text-primary-foreground font-semibold shadow-sm' : 'bg-transparent text-muted-foreground hover:text-foreground'"
                >
                    Necesita asesor
                </button>
            </div>

            <!-- Clients List -->
            <div class="flex-1 overflow-y-auto client-list-scroll">
                <Link
                    v-for="client in filteredClients"
                    :key="client.id"
                    :href="route('crm.chat', client.id)"
                    class="flex cursor-pointer items-center border-b border-border px-3 py-3 transition-all duration-150 hover:bg-accent"
                    :class="selectedClient?.id === client.id ? 'bg-accent/50 border-l-2 border-primary' : 'border-l-2 border-transparent'"
                >
                    <div class="relative mr-3">
                        <Avatar class="h-12 w-12">
                            <AvatarFallback class="text-base font-medium"><User class="h-6 w-6" /></AvatarFallback>
                        </Avatar>
                        <span class="absolute bottom-0 right-0 text-[10px] text-muted-foreground">{{ priorityIcons[client.priority] }}</span>
                        <span v-if="client.status === 'NECESITA ASESOR'" class="absolute -right-0.5 -top-0.5 flex h-4 w-4">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex h-4 w-4 rounded-full bg-red-400"></span>
                        </span>
                        <span v-else-if="client.status === 'PAGO RECIBIDO'" class="absolute -right-0.5 -top-0.5 flex h-4 w-4">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-4 w-4 rounded-full bg-emerald-400"></span>
                        </span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between">
                            <h3 class="truncate text-base font-medium text-foreground">{{ client.name || client.phone }}</h3>
                            <span class="text-[12px] text-muted-foreground">{{ formatTime(client.last_interaction_at) }}</span>
                        </div>
                        <div class="mt-0.5 flex items-center justify-between">
                            <p class="flex items-center truncate text-sm text-muted-foreground">
                                <StatusBadge :status="client.status" />
                            </p>
                            <div v-if="client.priority === 'ALTA'" class="rounded-full bg-red-100 text-red-700 px-1.5 text-[10px] font-bold">
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
                <div v-if="filteredClients.length === 0" class="flex items-center justify-center py-12 text-sm text-muted-foreground">
                    No se encontraron chats
                </div>
            </div>
        </div>

        <!-- Main Chat Area & CRM panel -->
        <div v-if="selectedClient" class="relative flex flex-1 flex-row bg-background">

            <!-- Left Chat Pane -->
            <div class="flex flex-1 flex-col bg-background border-r border-border">
                <!-- Chat Header -->
                <div class="z-10 flex h-[60px] items-center justify-between bg-card px-4 py-2 border-b border-border">
                    <div class="flex items-center">
                        <Avatar class="mr-3 h-10 w-10">
                            <AvatarFallback><User class="h-5 w-5" /></AvatarFallback>
                        </Avatar>
                        <div>
                            <h2 class="text-base font-medium leading-tight text-foreground">{{ selectedClient.name || selectedClient.phone }}</h2>
                            <p class="text-[12px] text-muted-foreground">{{ selectedClient.status }}</p>
                        </div>
                        <div v-if="selectedClient.status === 'NECESITA ASESOR'" class="ml-4 flex items-center gap-2 rounded-full bg-red-50 border border-red-200 px-3 py-1 animate-pulse">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-red-400"></span>
                            </span>
                            <span class="text-xs font-bold text-red-700">NECESITA ASESOR</span>
                        </div>
                        <div v-else-if="selectedClient.status === 'PAGO RECIBIDO'" class="ml-4 flex items-center gap-2 rounded-full bg-emerald-50 border border-emerald-200 px-3 py-1 animate-pulse">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                            </span>
                            <span class="text-xs font-bold text-emerald-700"><DollarSign class="w-4 h-4 inline mr-1" /> PAGO RECIBIDO — Validar</span>
                        </div>
                        <div v-else-if="selectedClient.status === 'VERIFICARYAPE'" class="ml-4 flex items-center gap-2 rounded-full bg-amber-50 border border-amber-200 px-3 py-1 animate-pulse">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
                                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                            </span>
                            <span class="text-xs font-bold text-amber-700">VERIFICARYAPE — Revisar comprobante</span>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <div
                    ref="chatContainerRef"
                    id="chat-messages-container"
                    class="z-10 flex flex-1 flex-col gap-2 overflow-y-auto overflow-x-hidden p-6 bg-background"
                    @scroll="onChatScroll"
                >
                    <div
                        v-for="msg in localMessages"
                        :key="msg.id"
                        class="relative max-w-[65%] shrink-0 rounded-lg p-2.5 text-sm animate-in fade-in slide-in-from-bottom-1 duration-150"
                        :class="msg.from_me
                            ? 'self-end rounded-tr-none bg-primary/10 text-foreground'
                            : 'self-start rounded-tl-none bg-muted border border-border text-foreground'"
                    >
                        <p class="whitespace-pre-wrap break-words pr-12">{{ msg.body }}</p>
                        <span
                            class="absolute bottom-1.5 right-2.5 text-[10px] text-muted-foreground"
                        >
                            {{ formatTime(msg.created_at) }}
                        </span>
                    </div>
                    <div ref="chatEndRef" class="h-px w-full shrink-0" aria-hidden="true" />
                </div>

                <!-- CRM Action Bar -->
                <div class="z-10 flex gap-3 border-t border-border bg-card/50 px-4 py-3">
                    <Button
                        variant="default"
                        size="sm"
                        @click="selectedProductId = products && products.length > 0 ? products[0].id : null"
                    >
                        <Package class="w-4 h-4" />
                        Crear Venta
                    </Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        @click="updateClientStatus('PAGO RECIBIDO')"
                    >
                        <DollarSign class="w-4 h-4" />
                        Validar Pago
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100 hover:text-emerald-800"
                        @click="updateClientStatus('VENTA CUMPLIDA')"
                    >
                        <Check class="w-4 h-4" />
                        Venta Cumplida
                    </Button>
                </div>

                <!-- Input + Quick Replies dropdown -->
                <div class="relative z-10 bg-card/50 px-4 py-2 border-t border-border">
                    <!-- Quick replies suggester -->
                    <div v-if="showQuickReplies && filteredQuickReplies.length > 0"
                          class="absolute bottom-full left-4 right-4 mb-2 max-h-64 overflow-y-auto rounded-xl border border-border bg-card shadow-lg">
                        <div v-for="qr in filteredQuickReplies" :key="qr.id"
                             @click="applyQuickReply(qr)"
                             class="cursor-pointer border-b border-border px-4 py-3 hover:bg-accent last:border-0">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="font-mono text-xs text-foreground font-semibold">{{ qr.shortcut }}</span>
                                <span class="text-xs font-semibold text-muted-foreground">{{ qr.title }}</span>
                            </div>
                            <p class="mt-1 line-clamp-2 text-xs text-muted-foreground">{{ qr.body }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="flex-1 relative">
                            <input
                                id="crm-message-input"
                                v-model="messageInput"
                                @keydown="onMessageInputKeydown"
                                type="text"
                                placeholder="Escribe un mensaje aquí (usa / para atajos)"
                                class="w-full rounded-lg border border-border bg-muted/50 px-4 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                        </div>
                        <Button
                            type="button"
                            :disabled="!messageInput.trim() || isSending"
                            @click="store.sendManualMessage(scrollToBottom)"
                        >
                            {{ isSending ? 'Enviando…' : 'Enviar' }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Right Details Pane (CRM Panels) -->
            <div class="w-[380px] flex flex-col bg-background overflow-y-auto p-4 border-l border-border gap-4">

                <!-- Client Card Header -->
                <Card>
                    <CardContent class="pt-6">
                        <div class="text-center">
                            <Avatar size="base" shape="circle" class="mx-auto mb-3 ring-2 ring-ring/20">
                                <AvatarFallback><User class="h-8 w-8" /></AvatarFallback>
                            </Avatar>
                            <h3 class="text-lg font-bold truncate text-foreground">{{ selectedClient.name || 'Cliente sin nombre' }}</h3>
                            <p class="text-sm text-muted-foreground mt-0.5">{{ selectedClient.phone }}</p>
                            <p v-if="selectedClient.lead_score && selectedClient.lead_score >= 70"
                               class="mt-2 inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-xs font-bold text-amber-700">
                                <TrendingUp class="w-3 h-3" />
                                Lead caliente — score {{ selectedClient.lead_score }}
                            </p>

                            <!-- Client Status dropdown -->
                            <div class="mt-4">
                                <label class="block text-xs font-semibold text-muted-foreground text-left mb-1.5">Estado del Cliente</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 w-2 h-2 rounded-full z-10"
                                          :class="{
                                              'bg-blue-400': selectedClient.status === 'NUEVO',
                                              'bg-yellow-400': selectedClient.status === 'INTERESADO',
                                              'bg-orange-400': selectedClient.status === 'CONSULTANDO',
                                              'bg-purple-400': selectedClient.status === 'ESPERANDO PAGO' || selectedClient.status === 'VERIFICARYAPE',
                                              'bg-emerald-400': selectedClient.status === 'PAGO RECIBIDO' || selectedClient.status === 'VENTA CUMPLIDA',
                                              'bg-red-400': selectedClient.status === 'NECESITA ASESOR',
                                              'bg-gray-400': !['NUEVO','INTERESADO','CONSULTANDO','ESPERANDO PAGO','VERIFICARYAPE','PAGO RECIBIDO','VENTA CUMPLIDA','NECESITA ASESOR'].includes(selectedClient.status)
                                          }">
                                    </span>
                                    <select
                                        :value="selectedClient.status"
                                        @change="updateClientStatus(($event.target as HTMLSelectElement).value)"
                                        class="w-full rounded-lg bg-muted border border-border py-1.5 pl-6 pr-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring appearance-none"
                                    >
                                        <option value="NUEVO">NUEVO</option>
                                        <option value="INTERESADO">INTERESADO</option>
                                        <option value="CONSULTANDO">CONSULTANDO</option>
                                        <option value="ESPERANDO PAGO">ESPERANDO PAGO</option>
                                        <option value="VERIFICARYAPE">VERIFICARYAPE</option>
                                        <option value="PAGO RECIBIDO">PAGO RECIBIDO</option>
                                        <option value="VENTA CUMPLIDA">VENTA CUMPLIDA</option>
                                        <option value="NECESITA ASESOR">NECESITA ASESOR</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Assigned to -->
                            <div class="mt-3">
                                <label class="block text-xs font-semibold text-muted-foreground text-left mb-1.5">Asignado a</label>
                                <select
                                    :value="selectedClient.assigned_user_id ?? ''"
                                    @change="assignUser(($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)"
                                    class="w-full rounded-lg bg-muted border border-border py-1.5 px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                >
                                    <option value="">— Sin asignar —</option>
                                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </div>

                            <!-- Payment Receipt Verification -->
                            <div v-if="selectedClient.status === 'VERIFICARYAPE' && selectedClient.payment_receipt_url" class="mt-4 rounded-xl bg-muted/50 border border-border p-4">
                                <h4 class="text-sm font-bold text-foreground mb-3">Comprobante de Pago</h4>
                                <div class="mb-3">
                                    <img :src="selectedClient.payment_receipt_url" alt="Comprobante" class="mx-auto max-h-48 rounded-lg border border-border" />
                                </div>
                                <div v-if="selectedClient.paid_amount" class="mb-3 text-sm">
                                    <span class="text-muted-foreground">Monto detectado:</span>
                                    <span class="ml-2 font-bold text-foreground">S/ {{ selectedClient.paid_amount.toFixed(2) }}</span>
                                </div>
                                <div class="flex gap-2">
                                    <Button variant="default" size="sm" class="flex-1 bg-emerald-600 hover:bg-emerald-500" @click="approvePayment">
                                        <Check class="w-4 h-4" />Aprobar
                                    </Button>
                                    <Button variant="outline" size="sm" class="flex-1" @click="rejectPayment">
                                        <X class="w-4 h-4" />Rechazar
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Tags -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider flex items-center gap-1.5">
                            <Tag class="w-4 h-4" />
                            Etiquetas
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="flex flex-wrap gap-1.5 mb-3">
                            <button
                                v-for="tag in allTags" :key="tag.id"
                                @click="toggleTag(tag.id)"
                                :style="{ borderColor: tag.color, color: clientHasTag(tag.id) ? '#fff' : tag.color, backgroundColor: clientHasTag(tag.id) ? tag.color : 'transparent' }"
                                class="rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-all hover:opacity-80"
                            >
                                {{ clientHasTag(tag.id) ? '✓ ' : '' }}{{ tag.name }}
                            </button>
                            <p v-if="!allTags || allTags.length === 0" class="text-xs text-muted-foreground">Sin etiquetas creadas todavía.</p>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <input v-model="newTagName" type="text" placeholder="Nueva etiqueta"
                                   class="flex-1 rounded-md bg-muted border border-border py-1 px-2 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-ring placeholder:text-muted-foreground" />
                            <input v-model="newTagColor" type="color"
                                   class="h-7 w-7 rounded cursor-pointer bg-transparent border border-border" />
                            <Button variant="default" size="icon" class="h-7 w-7" @click="createTag">
                                <Plus class="w-3 h-3" />
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <!-- Internal Notes -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider flex items-center gap-1.5">
                            <Clock class="w-4 h-4" />
                            Notas internas
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="flex gap-2 mb-3">
                            <textarea v-model="noteInput" rows="2" placeholder="Escribe una nota privada (solo el equipo la ve)"
                                      class="flex-1 rounded-md bg-muted border border-border py-1.5 px-2 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-ring resize-none placeholder:text-muted-foreground"></textarea>
                            <Button variant="default" size="sm" :disabled="!noteInput.trim()" class="self-start" @click="addNote">
                                Guardar
                            </Button>
                        </div>

                        <div v-if="clientNotes && clientNotes.length > 0" class="space-y-2 max-h-60 overflow-y-auto pr-1">
                            <div v-for="n in clientNotes" :key="n.id"
                                 class="group rounded-md p-2 text-xs border"
                                 :class="n.user ? 'bg-muted/50 border-border' : 'bg-muted/30 border-border/50'">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="whitespace-pre-wrap text-foreground">{{ n.body }}</p>
                                    <button @click="deleteNote(n.id)"
                                            class="opacity-0 group-hover:opacity-100 text-muted-foreground hover:text-foreground text-[10px] transition">
                                        <X class="w-3 h-3" />
                                    </button>
                                </div>
                                <p class="mt-1 text-[10px] flex items-center gap-1 text-muted-foreground">
                                    <span v-if="!n.user" class="font-bold">AI IA</span>
                                    <span v-else>{{ n.user.name }}</span>
                                    · {{ formatDateTime(n.created_at) }}
                                </p>
                            </div>
                        </div>
                        <p v-else class="text-xs text-muted-foreground italic">Sin notas todavía.</p>
                    </CardContent>
                </Card>

                <!-- Create Direct Order (Venta Cumplida & Deduct Stock) -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider flex items-center gap-1.5">
                            <Package class="w-4 h-4" />
                            Registrar Venta
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form @submit.prevent="submitSale" class="space-y-3.5">
                            <!-- Product Selection -->
                            <div>
                                <label class="block text-xs text-muted-foreground mb-1">Producto</label>
                                <select
                                    v-model="selectedProductId"
                                    class="w-full rounded-lg bg-muted border border-border py-1.5 px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                >
                                    <option :value="null">-- Seleccionar Producto --</option>
                                    <option v-for="prod in products" :key="prod.id" :value="prod.id">
                                        {{ prod.name }} (S/ {{ prod.price }})
                                    </option>
                                </select>
                            </div>

                            <!-- Variant (Color & Size) Selection -->
                            <div v-if="selectedProductId">
                                <label class="block text-xs text-muted-foreground mb-1">Variante (Color & Talla)</label>
                                <select
                                    v-model="selectedVariantId"
                                    required
                                    class="w-full rounded-lg bg-muted border border-border py-1.5 px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
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
                                    <label class="block text-xs text-muted-foreground mb-1">Cantidad</label>
                                    <input
                                        v-model.number="saleQuantity"
                                        type="number"
                                        min="1"
                                        required
                                        class="w-full rounded-lg bg-muted border border-border py-1.5 px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs text-muted-foreground mb-1">Método Envío</label>
                                    <select
                                        v-model="saleShippingMethod"
                                        class="w-full rounded-lg bg-muted border border-border py-1.5 px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                    >
                                        <option value="Directo">Directo</option>
                                        <option value="Motorizado">Motorizado</option>
                                        <option value="Shalom">Shalom</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Shipping Address -->
                            <div v-if="selectedVariantId">
                                <label class="block text-xs text-muted-foreground mb-1">Dirección de Envío</label>
                                <input
                                    v-model="saleShippingAddress"
                                    type="text"
                                    placeholder="Dirección del cliente"
                                    class="w-full rounded-lg bg-muted border border-border py-1.5 px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring placeholder:text-muted-foreground"
                                />
                            </div>

                            <Button
                                v-if="selectedVariantId"
                                type="submit"
                                class="w-full"
                            >
                                <Check class="w-4 h-4" />
                                Registrar Venta
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <!-- Past Orders History -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider flex items-center gap-1.5">
                            <Package class="w-4 h-4" />
                            Pedidos Registrados
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="orders && orders.length > 0" class="space-y-3 max-h-[300px] overflow-y-auto pr-1">
                            <div
                                v-for="order in orders"
                                :key="order.id"
                                class="p-2.5 rounded-lg bg-muted/50 border border-border text-xs"
                            >
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="font-bold text-foreground">Pedido #{{ order.id }}</span>
                                    <span
                                        class="px-1.5 py-0.5 rounded text-[10px] font-bold"
                                        :class="order.status === 'COMPLETADA' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200'"
                                    >
                                        {{ order.status }}
                                    </span>
                                </div>

                                <!-- Items List -->
                                <div v-for="item in order.items" :key="item.id" class="text-muted-foreground mt-0.5">
                                    • {{ item.variant?.product?.name || 'Producto' }} ({{ item.variant?.color }}, {{ item.variant?.size }}) x{{ item.quantity }}
                                </div>

                                <div class="mt-2 pt-1.5 border-t border-border flex justify-between items-center">
                                    <span class="text-muted-foreground text-[10px]">Envío: {{ order.shipping_method }}</span>
                                    <span class="font-bold text-foreground">Total: S/ {{ order.total }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-else class="text-center py-6 text-sm text-muted-foreground">
                            Ningún pedido registrado aún.
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>

        <!-- Empty State -->
        <div v-else class="relative flex flex-1 flex-col items-center justify-center bg-background">
            <div class="w-[300px] text-center">
                <div class="mb-6 flex justify-center opacity-10">
                    <div class="w-20 h-20 rounded-full bg-muted flex items-center justify-center">
                        <MessageCircle class="w-10 h-10 text-muted-foreground" />
                    </div>
                </div>
                <h1 class="mb-3 text-[32px] font-light text-foreground">WhatsApp CRM Roma</h1>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Gestiona tus ventas de TikTok Live de forma automatizada con IA.
                    Selecciona un chat para ver los detalles.
                </p>
            </div>
            <div class="absolute bottom-10 flex items-center gap-2 text-sm text-muted-foreground">
                Protegido por Gemini 2.0 Flash
            </div>
        </div>
        </div>

    </AppLayout>
</template>

<style scoped>
/* Custom scrollbar - light mode */
#chat-messages-container::-webkit-scrollbar,
.client-list-scroll::-webkit-scrollbar {
  width: 6px;
}
#chat-messages-container::-webkit-scrollbar-track,
.client-list-scroll::-webkit-scrollbar-track {
  background: transparent;
}
#chat-messages-container::-webkit-scrollbar-thumb,
.client-list-scroll::-webkit-scrollbar-thumb {
  background: hsl(var(--border));
  border-radius: 3px;
}
#chat-messages-container::-webkit-scrollbar-thumb:hover,
.client-list-scroll::-webkit-scrollbar-thumb:hover {
  background: hsl(var(--muted-foreground));
}
</style>