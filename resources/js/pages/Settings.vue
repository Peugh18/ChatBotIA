<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Settings, CreditCard, Clock, Truck, Bot, Building2, Save, CheckCircle, AlertCircle } from 'lucide-vue-next';

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

        <div class="min-h-[calc(100vh-4rem)] bg-gray-900 p-6 text-gray-100">
            <div class="mx-auto max-w-4xl">
                <div class="rounded-2xl border border-gray-700 bg-gray-800 p-6 shadow-xl">
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold flex items-center gap-2">
                                <Settings class="w-7 h-7" />
                                Configuración del Negocio
                            </h1>
                            <p class="text-sm text-gray-400">Ajusta los datos que usa el bot para cotizar, pagar y responder.</p>
                        </div>
                    </div>

                    <form @submit.prevent="save" class="space-y-6">
                        <section class="rounded-xl border border-gray-700 bg-gray-900 p-5">
                            <h2 class="mb-4 text-lg font-bold text-white flex items-center gap-2">
                                <CreditCard class="w-5 h-5" />
                                Pagos Yape
                            </h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-gray-400">Número Yape</label>
                                    <input v-model="form.yape_number" type="text" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-gray-400">Titular</label>
                                    <input v-model="form.yape_holder" type="text" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                                </div>
                            </div>
                        </section>

                        <section class="rounded-xl border border-gray-700 bg-gray-900 p-5">
                            <h2 class="mb-4 text-lg font-bold text-white flex items-center gap-2">
                                <Clock class="w-5 h-5" />
                                Horarios y Ventanas
                            </h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-gray-400">Horario de atención</label>
                                    <input v-model="form.business_hours" type="text" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-gray-400">Ventana motorizado</label>
                                    <input v-model="form.motorizado_window" type="text" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                                </div>
                            </div>
                        </section>

                        <section class="rounded-xl border border-gray-700 bg-gray-900 p-5">
                            <h2 class="mb-4 text-lg font-bold text-white flex items-center gap-2">
                                <Truck class="w-5 h-5" />
                                Delivery Shalom
                            </h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-gray-400">Costo Lima (S/)</label>
                                    <input v-model.number="form.shalom_lima" type="number" step="0.01" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-gray-400">Costo Provincia (S/)</label>
                                    <input v-model.number="form.shalom_provincia" type="number" step="0.01" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                                </div>
                            </div>
                        </section>

                        <section class="rounded-xl border border-gray-700 bg-gray-900 p-5">
                            <h2 class="mb-4 text-lg font-bold text-white flex items-center gap-2">
                                <Bot class="w-5 h-5" />
                                Automatizaciones
                            </h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-gray-400">Máximo follow-ups por cliente</label>
                                    <input v-model.number="form.max_followups" type="number" min="0" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                                </div>
                                <div class="flex items-center gap-2">
                                    <input v-model="form.followup_enabled" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-white focus:ring-white" />
                                    <label class="text-sm text-white">Activar follow-ups automáticos</label>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-xl border border-gray-700 bg-gray-900 p-5">
                            <h2 class="mb-4 text-lg font-bold text-white flex items-center gap-2">
                                <Building2 class="w-5 h-5" />
                                Marca
                            </h2>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-gray-400">Nombre de la tienda</label>
                                    <input v-model="form.store_name" type="text" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-gray-400">Firma en mensajes</label>
                                    <input v-model="form.store_signature" type="text" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                                </div>
                            </div>
                        </section>

                        <div class="flex items-center justify-between">
                            <p v-if="isDirty" class="text-xs text-yellow-400 flex items-center gap-1">
                                <AlertCircle class="w-3 h-3" />
                                Hay cambios sin guardar
                            </p>
                            <p v-else class="text-xs text-green-400 flex items-center gap-1">
                                <CheckCircle class="w-3 h-3" />
                                Todo guardado
                            </p>
                            <button type="submit" :disabled="!isDirty" class="rounded-lg bg-white px-6 py-2 text-sm font-bold text-gray-900 hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1">
                                <Save class="w-4 h-4" />
                                Guardar cambios
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
