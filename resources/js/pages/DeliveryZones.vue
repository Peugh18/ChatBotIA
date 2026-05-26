<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Bike, Plus, Pencil, Trash2, X, MapPin, CheckCircle, XCircle } from 'lucide-vue-next';

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

        <div class="min-h-[calc(100vh-4rem)] bg-gray-900 p-6 text-gray-100">
            <div class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-[360px_1fr]">
                <section class="rounded-2xl border border-gray-700 bg-gray-800 p-5">
                    <div class="mb-5">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-white">Roma Store</p>
                        <h1 class="mt-2 text-2xl font-bold flex items-center gap-2">
                            <Bike class="w-7 h-7" />
                            Tarifas de delivery
                        </h1>
                        <p class="mt-2 text-sm leading-relaxed text-gray-400">
                            Administra la tabla que usa el bot para cotizar delivery por motorizado en Lima.
                        </p>
                    </div>

                    <div class="mb-5 grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-gray-700 bg-gray-900 p-3">
                            <p class="text-xs text-gray-400">Distritos</p>
                            <p class="mt-1 text-2xl font-bold text-white">{{ sortedDeliveryZones.length }}</p>
                        </div>
                        <div class="rounded-xl border border-gray-700 bg-gray-900 p-3">
                            <p class="text-xs text-gray-400">Activos</p>
                            <p class="mt-1 text-2xl font-bold text-white">{{ activeZonesCount }}</p>
                        </div>
                    </div>

                    <form @submit.prevent="saveZone" class="space-y-4">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-white flex items-center gap-2">
                            <Plus v-if="!editingDeliveryZoneId" class="w-4 h-4" />
                            <Pencil v-else class="w-4 h-4" />
                            {{ editingDeliveryZoneId ? 'Editar tarifa' : 'Nueva tarifa' }}
                        </h2>

                        <div>
                            <label class="mb-1 block text-xs text-gray-400">Distrito</label>
                            <input v-model="form.district" required type="text" placeholder="Ej. Miraflores" class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs text-gray-400">Motorizado S/</label>
                                <input v-model.number="form.motorizado_cost" required min="0" step="0.01" type="number" class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-gray-400">Shalom S/</label>
                                <input v-model.number="form.shalom_cost" min="0" step="0.01" type="number" class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs text-gray-400">Región</label>
                            <input v-model="form.region" type="text" class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:border-white focus:ring-white" />
                        </div>

                        <label class="flex items-center gap-2 text-sm text-white">
                            <input v-model="form.active" type="checkbox" class="rounded border-gray-600 bg-gray-800 text-white focus:ring-white" />
                            Activo para cotización del bot
                        </label>

                        <div>
                            <label class="mb-1 block text-xs text-gray-400">Notas internas</label>
                            <textarea v-model="form.notes" rows="3" class="w-full resize-none rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:border-white focus:ring-white"></textarea>
                        </div>

                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 rounded-lg bg-white px-4 py-2 text-sm font-bold text-gray-900 transition hover:bg-gray-200 flex items-center justify-center gap-1">
                                <Plus v-if="!editingDeliveryZoneId" class="w-4 h-4" />
                                <Pencil v-else class="w-4 h-4" />
                                {{ editingDeliveryZoneId ? 'Guardar cambios' : 'Crear tarifa' }}
                            </button>
                            <button v-if="editingDeliveryZoneId" type="button" @click="resetForm" class="rounded-lg border border-gray-600 px-4 py-2 text-sm font-bold text-gray-400 hover:text-white flex items-center gap-1">
                                <X class="w-4 h-4" />
                                Cancelar
                            </button>
                        </div>
                    </form>
                </section>

                <section class="overflow-hidden rounded-2xl border border-gray-700 bg-gray-800">
                    <div class="flex items-center justify-between border-b border-gray-700 bg-gray-900 px-5 py-4">
                        <div>
                            <h2 class="text-lg font-bold flex items-center gap-2">
                                <MapPin class="w-5 h-5" />
                                Tabla actual
                            </h2>
                            <p class="text-xs text-gray-400">Estos precios alimentan el flujo inteligente de ventas.</p>
                        </div>
                    </div>

                    <div class="max-h-[calc(100vh-13rem)] overflow-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 bg-gray-900 text-xs uppercase text-gray-400">
                                <tr>
                                    <th class="px-4 py-3">Distrito</th>
                                    <th class="px-4 py-3">Motorizado</th>
                                    <th class="px-4 py-3">Shalom</th>
                                    <th class="px-4 py-3">Región</th>
                                    <th class="px-4 py-3">Estado</th>
                                    <th class="px-4 py-3 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-700">
                                <tr v-for="zone in sortedDeliveryZones" :key="zone.id" class="hover:bg-gray-700">
                                    <td class="px-4 py-3 font-semibold text-white">{{ zone.district }}</td>
                                    <td class="px-4 py-3 font-bold text-white">S/ {{ Number(zone.motorizado_cost).toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-gray-300">S/ {{ Number(zone.shalom_cost).toFixed(2) }}</td>
                                    <td class="px-4 py-3 text-gray-400">{{ zone.region }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-bold flex items-center gap-1 w-fit" :class="zone.active ? 'bg-white/10 text-white' : 'bg-gray-700 text-gray-400'">
                                            <CheckCircle v-if="zone.active" class="w-3 h-3" />
                                            <XCircle v-else class="w-3 h-3" />
                                            {{ zone.active ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex justify-end gap-2">
                                            <button @click="editZone(zone)" class="rounded-md bg-gray-700 px-3 py-1 text-xs font-bold text-white hover:bg-gray-600 flex items-center gap-1">
                                                <Pencil class="w-3 h-3" />
                                                Editar
                                            </button>
                                            <button @click="deleteZone(zone)" class="rounded-md bg-red-900/20 px-3 py-1 text-xs font-bold text-red-400 hover:bg-red-900/30 flex items-center gap-1">
                                                <Trash2 class="w-3 h-3" />
                                                Eliminar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="sortedDeliveryZones.length === 0">
                                    <td colspan="6" class="px-4 py-10 text-center text-gray-400">Aún no hay tarifas creadas.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
