<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface DeliveryZone {
    id: number;
    district: string;
    motorizado_cost: string | number;
    shalom_cost: string | number;
    region: string;
    active: boolean;
    notes?: string | null;
}

const props = defineProps<{
    deliveryZones: DeliveryZone[];
}>();

const editingDeliveryZoneId = ref<number | null>(null);
const form = ref({
    district: '',
    motorizado_cost: 0,
    shalom_cost: 10,
    region: 'Lima',
    active: true,
    notes: '',
});

const sortedDeliveryZones = computed(() =>
    [...(props.deliveryZones || [])].sort((a, b) => a.district.localeCompare(b.district))
);

const activeZonesCount = computed(() => sortedDeliveryZones.value.filter(z => z.active).length);

function resetForm() {
    editingDeliveryZoneId.value = null;
    form.value = {
        district: '',
        motorizado_cost: 0,
        shalom_cost: 10,
        region: 'Lima',
        active: true,
        notes: '',
    };
}

function editZone(zone: DeliveryZone) {
    editingDeliveryZoneId.value = zone.id;
    form.value = {
        district: zone.district,
        motorizado_cost: Number(zone.motorizado_cost),
        shalom_cost: Number(zone.shalom_cost ?? 10),
        region: zone.region || 'Lima',
        active: Boolean(zone.active),
        notes: zone.notes || '',
    };
}

function saveZone() {
    const payload = { ...form.value };
    const options = {
        preserveScroll: true,
        onSuccess: () => resetForm(),
        onError: (errs: any) => {
            alert(errs.district || errs.motorizado_cost || 'No se pudo guardar la tarifa.');
        },
    };

    if (editingDeliveryZoneId.value) {
        router.put(route('delivery-zones.update', editingDeliveryZoneId.value), payload, options);
        return;
    }

    router.post(route('delivery-zones.store'), payload, options);
}

function deleteZone(zone: DeliveryZone) {
    if (!confirm(`¿Eliminar la tarifa de ${zone.district}?`)) return;
    router.delete(route('delivery-zones.destroy', zone.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (editingDeliveryZoneId.value === zone.id) resetForm();
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Tarifas Delivery', href: '/delivery-zones' }]">
        <Head title="Tarifas Delivery" />

        <div class="min-h-[calc(100vh-4rem)] bg-[#0b141a] p-6 text-[#e9edef]">
            <div class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-[360px_1fr]">
                <section class="rounded-2xl border border-[#202c33] bg-[#111b21] p-5 shadow-xl">
                    <div class="mb-5">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#00a884]">Roma Store</p>
                        <h1 class="mt-2 text-2xl font-bold">🛵 Tarifas de delivery</h1>
                        <p class="mt-2 text-sm leading-relaxed text-[#8696a0]">
                            Administra la tabla que usa el bot para cotizar delivery por motorizado en Lima.
                        </p>
                    </div>

                    <div class="mb-5 grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-[#202c33] bg-[#202c33]/50 p-3">
                            <p class="text-xs text-[#8696a0]">Distritos</p>
                            <p class="mt-1 text-2xl font-bold text-[#e9edef]">{{ sortedDeliveryZones.length }}</p>
                        </div>
                        <div class="rounded-xl border border-[#202c33] bg-[#202c33]/50 p-3">
                            <p class="text-xs text-[#8696a0]">Activos</p>
                            <p class="mt-1 text-2xl font-bold text-[#00a884]">{{ activeZonesCount }}</p>
                        </div>
                    </div>

                    <form @submit.prevent="saveZone" class="space-y-4">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-[#00a884]">
                            {{ editingDeliveryZoneId ? 'Editar tarifa' : 'Nueva tarifa' }}
                        </h2>

                        <div>
                            <label class="mb-1 block text-xs text-[#8696a0]">Distrito</label>
                            <input v-model="form.district" required type="text" placeholder="Ej. Miraflores" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs text-[#8696a0]">Motorizado S/</label>
                                <input v-model.number="form.motorizado_cost" required min="0" step="0.01" type="number" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-[#8696a0]">Shalom S/</label>
                                <input v-model.number="form.shalom_cost" min="0" step="0.01" type="number" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs text-[#8696a0]">Región</label>
                            <input v-model="form.region" type="text" class="w-full rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" />
                        </div>

                        <label class="flex items-center gap-2 text-sm text-[#e9edef]">
                            <input v-model="form.active" type="checkbox" class="rounded border-[#202c33] bg-[#0b141a] text-[#00a884] focus:ring-[#00a884]" />
                            Activo para cotización del bot
                        </label>

                        <div>
                            <label class="mb-1 block text-xs text-[#8696a0]">Notas internas</label>
                            <textarea v-model="form.notes" rows="3" class="w-full resize-none rounded-lg border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]"></textarea>
                        </div>

                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 rounded-lg bg-[#00a884] px-4 py-2 text-sm font-bold text-[#111b21] transition hover:bg-[#06cf9c]">
                                {{ editingDeliveryZoneId ? 'Guardar cambios' : 'Crear tarifa' }}
                            </button>
                            <button v-if="editingDeliveryZoneId" type="button" @click="resetForm" class="rounded-lg border border-[#202c33] px-4 py-2 text-sm font-bold text-[#8696a0] hover:text-[#e9edef]">
                                Cancelar
                            </button>
                        </div>
                    </form>
                </section>

                <section class="overflow-hidden rounded-2xl border border-[#202c33] bg-[#111b21] shadow-xl">
                    <div class="flex items-center justify-between border-b border-[#202c33] bg-[#202c33]/50 px-5 py-4">
                        <div>
                            <h2 class="text-lg font-bold">Tabla actual</h2>
                            <p class="text-xs text-[#8696a0]">Estos precios alimentan el flujo inteligente de ventas.</p>
                        </div>
                    </div>

                    <div class="max-h-[calc(100vh-13rem)] overflow-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 bg-[#202c33] text-xs uppercase text-[#8696a0]">
                                <tr>
                                    <th class="px-4 py-3">Distrito</th>
                                    <th class="px-4 py-3">Motorizado</th>
                                    <th class="px-4 py-3">Shalom</th>
                                    <th class="px-4 py-3">Región</th>
                                    <th class="px-4 py-3">Estado</th>
                                    <th class="px-4 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#202c33]">
                                <tr v-for="zone in sortedDeliveryZones" :key="zone.id" class="hover:bg-[#202c33]/50">
                                    <td class="px-4 py-3 font-semibold">{{ zone.district }}</td>
                                    <td class="px-4 py-3 font-bold text-[#00a884]">S/ {{ Number(zone.motorizado_cost).toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-[#e9edef]">S/ {{ Number(zone.shalom_cost).toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-[#8696a0]">{{ zone.region }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-bold" :class="zone.active ? 'bg-[#22c55e]/20 text-[#22c55e]' : 'bg-[#8696a0]/20 text-[#8696a0]'">
                                            {{ zone.active ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex justify-end gap-2">
                                            <button @click="editZone(zone)" class="rounded-md bg-[#2a3942] px-3 py-1 text-xs font-bold text-[#e9edef] hover:bg-[#374045]">Editar</button>
                                            <button @click="deleteZone(zone)" class="rounded-md bg-[#ef4444]/20 px-3 py-1 text-xs font-bold text-[#f87171] hover:bg-[#ef4444]/30">Eliminar</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="sortedDeliveryZones.length === 0">
                                    <td colspan="6" class="px-4 py-10 text-center text-[#8696a0]">Aún no hay tarifas creadas.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
