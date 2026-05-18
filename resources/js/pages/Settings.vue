<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    settings: Record<string, string>;
}>();

const form = ref<Record<string, string>>({ ...props.settings });

const isDirty = computed(() => {
    return Object.keys(form.value).some(key => form.value[key] !== props.settings[key]);
});

function save() {
    router.put(route('business-settings.update'), { settings: form.value }, {
        preserveScroll: true,
        onSuccess: () => {
            Object.assign(props.settings, form.value);
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Configuración', href: '/business-settings' }]">
        <Head title="Configuración del Negocio" />

        <div class="min-h-[calc(100vh-4rem)] bg-[#0b141a] p-6 text-[#e9edef]">
            <div class="mx-auto max-w-4xl">
                <div class="rounded-2xl border border-[#202c33] bg-[#111b21] p-6 shadow-xl">
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold">Configuración del Negocio</h1>
                            <p class="text-sm text-[#8696a0]">Ajusta los datos que usa el bot para cotizar, pagar y responder.</p>
                        </div>
                    </div>

                    <form @submit.prevent="save" class="space-y-6">
                        <section class="rounded-xl border border-[#202c33] bg-[#202c33]/30 p-5">
                            <h2 class="mb-4 text-lg font-bold text-[#00a884]">Pagos Yape</h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-[#8696a0]">Número Yape</label>
                                    <input v-model="form.yape_number" type="text" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-[#8696a0]">Titular</label>
                                    <input v-model="form.yape_holder" type="text" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                                </div>
                            </div>
                        </section>

                        <section class="rounded-xl border border-[#202c33] bg-[#202c33]/30 p-5">
                            <h2 class="mb-4 text-lg font-bold text-[#00a884]">Horarios y Ventanas</h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-[#8696a0]">Horario de atención</label>
                                    <input v-model="form.business_hours" type="text" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-[#8696a0]">Ventana motorizado</label>
                                    <input v-model="form.motorizado_window" type="text" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                                </div>
                            </div>
                        </section>

                        <section class="rounded-xl border border-[#202c33] bg-[#202c33]/30 p-5">
                            <h2 class="mb-4 text-lg font-bold text-[#00a884]">Delivery Shalom</h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-[#8696a0]">Costo Lima (S/)</label>
                                    <input v-model.number="form.shalom_lima" type="number" step="0.01" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-[#8696a0]">Costo Provincia (S/)</label>
                                    <input v-model.number="form.shalom_provincia" type="number" step="0.01" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                                </div>
                            </div>
                        </section>

                        <section class="rounded-xl border border-[#202c33] bg-[#202c33]/30 p-5">
                            <h2 class="mb-4 text-lg font-bold text-[#00a884]">Automatizaciones</h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-[#8696a0]">Máximo follow-ups por cliente</label>
                                    <input v-model.number="form.max_followups" type="number" min="0" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                                </div>
                                <div class="flex items-center gap-2">
                                    <input v-model="form.followup_enabled" type="checkbox" class="rounded border-[#202c33] bg-[#0b141a] text-[#00a884] focus:ring-[#00a884]" />
                                    <label class="text-sm text-[#e9edef]">Activar follow-ups automáticos</label>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-xl border border-[#202c33] bg-[#202c33]/30 p-5">
                            <h2 class="mb-4 text-lg font-bold text-[#00a884]">Marca</h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-[#8696a0]">Nombre de la tienda</label>
                                    <input v-model="form.store_name" type="text" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-[#8696a0]">Firma en mensajes</label>
                                    <input v-model="form.store_signature" type="text" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                                </div>
                            </div>
                        </section>

                        <div class="flex items-center justify-between">
                            <p v-if="isDirty" class="text-xs text-[#f59e0b]">Hay cambios sin guardar</p>
                            <p v-else class="text-xs text-[#22c55e]">Todo guardado</p>
                            <button type="submit" :disabled="!isDirty" class="rounded-lg bg-[#00a884] px-6 py-2 text-sm font-bold text-[#111b21] hover:bg-[#06cf9c] disabled:opacity-50 disabled:cursor-not-allowed">
                                Guardar cambios
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
