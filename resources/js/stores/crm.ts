import { defineStore } from 'pinia';
import axios from 'axios';
import { playIncomingMessageSound } from '@/composables/useIncomingMessageSound';
import echo from '@/echo';

export interface Tag { id: number; name: string; color: string }
export interface AssignedUser { id: number; name: string }
export interface ClientNote {
    id: number;
    body: string;
    created_at: string;
    user?: { id: number; name: string } | null;
}

export interface Client {
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
    payment_receipt_url?: string | null;
    paid_amount?: number | null;
}

export interface Message {
    id: number;
    body: string;
    from_me: boolean;
    created_at: string;
}

export interface QuickReply {
    id: number;
    shortcut: string;
    title: string;
    body: string;
}

export const useCrmStore = defineStore('crm', {
    state: () => ({
        clients: [] as Client[],
        selectedClient: null as Client | null,
        localMessages: [] as Message[],
        knownMessageIds: new Set<number>(),
        isSending: false,
        activeTab: 'todos',
        search: '',
        messageInput: '',
        noteInput: '',
        newTagName: '',
        newTagColor: '#00a884',
        showQuickReplies: false,
        pollTimer: null as ReturnType<typeof setInterval> | null,
        pollInFlight: false,
        stickToBottom: true,
    }),

    actions: {
        initialize(clients: Client[], selectedClient?: Client, messages?: Message[]) {
            this.clients = clients || [];
            this.selectedClient = selectedClient || null;
            if (messages) {
                this.initKnownMessages(messages);
            }
        },

        initKnownMessages(messages: Message[]) {
            this.knownMessageIds = new Set(messages.map((m) => m.id));
            this.localMessages = [...messages];
        },

        dedupeMessages(msgs: Message[]): Message[] {
            const seen = new Map<string, Message>();
            for (const m of msgs) {
                const minute = m.created_at ? new Date(m.created_at).toISOString().slice(0, 16) : '';
                const key = `${m.from_me ? 1 : 0}|${m.body.trim()}|${minute}`;
                if (!seen.has(key)) {
                    seen.set(key, m);
                }
            }
            return [...seen.values()].sort(
                (a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime(),
            );
        },

        applyServerMessages(serverMsgs: Message[], playSound = true, scrollToBottomCallback?: (force: boolean) => void) {
            const deduped = this.dedupeMessages(serverMsgs);
            const prev = this.knownMessageIds;
            const prevCount = this.localMessages.length;
            let hasNewInbound = false;
            let hasNew = false;

            for (const m of deduped) {
                if (!prev.has(m.id)) {
                    hasNew = true;
                    if (!m.from_me) {
                        hasNewInbound = true;
                    }
                }
            }

            this.knownMessageIds = new Set(deduped.map((m) => m.id));
            this.localMessages = deduped;

            if (hasNewInbound && playSound) {
                playIncomingMessageSound();
            }
            if (hasNewInbound || hasNew || deduped.length > prevCount || this.stickToBottom) {
                if (scrollToBottomCallback) {
                    scrollToBottomCallback(true);
                }
            }
        },

        async pollChatMessages(scrollToBottomCallback?: (force: boolean) => void) {
            if (!this.selectedClient || this.pollInFlight) return;
            this.pollInFlight = true;
            try {
                // @ts-ignore
                const { data } = await axios.get(route('crm.chat.poll', this.selectedClient.id));
                this.applyServerMessages(data.messages || [], true, scrollToBottomCallback);
                if (data.sync?.error) {
                    console.warn('[CRM] sync roma-api:', data.sync.error);
                }
            } catch (err) {
                console.warn('[CRM] poll falló — ¿sesión activa y Laravel en :8000?', err);
            } finally {
                this.pollInFlight = false;
            }
        },

        startChatPolling(scrollToBottomCallback?: (force: boolean) => void) {
            this.stopChatPolling();
            // Hacemos una única sincronización inicial al abrir el chat para traer los últimos mensajes.
            // A partir de ahí, dependemos 100% de Pusher en tiempo real sin consumir CPU con polling constante.
            void this.pollChatMessages(scrollToBottomCallback);
        },

        stopChatPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        async sendManualMessage(scrollToBottomCallback?: (force: boolean) => void) {
            const body = this.messageInput.trim();
            const client = this.selectedClient;
            if (!body || !client || this.isSending) return;

            this.isSending = true;
            const tempId = -Date.now();
            const optimistic: Message = {
                id: tempId,
                body,
                from_me: true,
                created_at: new Date().toISOString(),
            };

            this.localMessages.push(optimistic);
            this.messageInput = '';
            this.showQuickReplies = false;
            this.stickToBottom = true;
            if (scrollToBottomCallback) scrollToBottomCallback(true);

            try {
                // @ts-ignore
                const { data } = await axios.post(
                    // @ts-ignore
                    route('crm.client.send', client.id),
                    { body },
                    { headers: { Accept: 'application/json' } },
                );

                this.localMessages = this.localMessages.filter((m) => m.id !== tempId);
                if (data.message) {
                    this.localMessages.push(data.message);
                    this.knownMessageIds.add(data.message.id);
                }
                if (scrollToBottomCallback) scrollToBottomCallback(true);
            } catch (err: unknown) {
                this.localMessages = this.localMessages.filter((m) => m.id !== tempId);
                this.messageInput = body;
                const ax = err as { response?: { data?: { error?: string; message?: string } } };
                alert(ax.response?.data?.error || ax.response?.data?.message || 'No se pudo enviar el mensaje.');
            } finally {
                this.isSending = false;
            }
        },

        listenToDashboard(playAlertCallback: (newStatus: string, prevStatus: string | null) => void) {
            if (!echo) return;
            this.leaveDashboard();
            
            echo.channel('crm-dashboard')
                .listen('.client.updated', (e: any) => {
                    if (!e?.client) return;
                    const idx = this.clients.findIndex((c) => c.id === e.client.id);
                    const prevStatus = idx !== -1 ? this.clients[idx].status : null;
                    const newStatus = e.client.status;

                    if (idx !== -1) {
                        Object.assign(this.clients[idx], e.client);
                    } else {
                        this.clients.unshift(e.client);
                    }

                    if (newStatus !== prevStatus) {
                        playAlertCallback(newStatus, prevStatus);
                    }
                });
        },

        leaveDashboard() {
            if (echo) {
                echo.leave('crm-dashboard');
            }
        },

        listenToClient(clientId: number, scrollToBottomCallback?: (force: boolean) => void) {
            if (!echo) return;
            this.leaveClient(clientId);

            echo.channel(`client.${clientId}`)
                .listen('.message.received', (e: { message?: Message }) => {
                    if (!e?.message) return;
                    const m = e.message;
                    if (this.knownMessageIds.has(m.id)) return;
                    this.knownMessageIds.add(m.id);
                    this.localMessages.push(m);
                    if (!m.from_me) playIncomingMessageSound();
                    if (scrollToBottomCallback) scrollToBottomCallback(true);
                });
        },

        leaveClient(clientId: number) {
            if (echo) {
                echo.leave(`client.${clientId}`);
            }
        }
    }
});
