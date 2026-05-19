<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Package, Plus, Pencil, Trash2, Tag, TrendingUp, X } from 'lucide-vue-next';

interface ProductVariant {
    id?: number;
    color: string;
    size: string;
    stock: number;
    image_url?: string;
    image?: File | null;
}

interface Product {
    id: number;
    name: string;
    sku: string;
    description: string;
    price: number;
    discount_percent: number | null;
    sales_count: number;
    image_url: string;
    ai_tags: string[];
    category: { id: number; name: string };
    variants: ProductVariant[];
}

interface Category {
    id: number;
    name: string;
}

const props = defineProps<{
    products: Product[];
    categories: Category[];
}>();

const showModal = ref(false);
const editingProduct = ref<Product | null>(null);
const tagsInput = ref('');

// Structured reactive reference to manage colors and their inner sizes
const colors = ref<{
    color: string;
    image?: File | null;
    image_url?: string;
    sizes: { size: string; stock: number }[];
}[]>([{ color: '', image: null, image_url: '', sizes: [{ size: '', stock: 0 }] }]);

const form = useForm({
    name: '',
    sku: '',
    description: '',
    price: '',
    discount_percent: '' as string,
    category_id: '',
    ai_tags: [] as string[],
    colors: [] as any[],
});

function openModal(product?: Product) {
    if (product) {
        editingProduct.value = product;
        form.name = product.name;
        form.sku = product.sku;
        form.description = product.description;
        form.price = String(product.price);
        form.discount_percent = product.discount_percent != null ? String(product.discount_percent) : '';
        form.category_id = String(product.category.id);
        tagsInput.value = product.ai_tags?.join(', ') || '';
        
        // Reconstruct nested colors and sizes from the flat product variants database entries
        const grouped: Record<string, { color: string; image_url: string; sizes: { size: string; stock: number }[] }> = {};
        product.variants?.forEach(v => {
            if (!grouped[v.color]) {
                grouped[v.color] = {
                    color: v.color,
                    image_url: v.image_url || '',
                    sizes: []
                };
            }
            grouped[v.color].sizes.push({
                size: v.size,
                stock: v.stock
            });
        });
        
        colors.value = Object.values(grouped);
        if (colors.value.length === 0) {
            colors.value = [{ color: '', image: null, image_url: '', sizes: [{ size: '', stock: 0 }] }];
        }
    } else {
        editingProduct.value = null;
        form.reset();
        tagsInput.value = '';
        colors.value = [{ color: '', image: null, image_url: '', sizes: [{ size: '', stock: 0 }] }];
    }
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingProduct.value = null;
}

function addColor() {
    colors.value.push({ color: '', image: null, image_url: '', sizes: [{ size: '', stock: 0 }] });
}

function removeColor(index: number) {
    colors.value.splice(index, 1);
}

function addSize(colorIndex: number) {
    colors.value[colorIndex].sizes.push({ size: '', stock: 0 });
}

function removeSize(colorIndex: number, sizeIndex: number) {
    colors.value[colorIndex].sizes.splice(sizeIndex, 1);
}

function handleColorFile(event: Event, index: number) {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        colors.value[index].image = input.files[0];
        // Dynamic preview URL
        colors.value[index].image_url = URL.createObjectURL(input.files[0]);
    }
}

function submitForm() {
    form.ai_tags = tagsInput.value.split(',').map(t => t.trim()).filter(Boolean);
    form.colors = colors.value.filter(c => c.color);

    if (editingProduct.value) {
        form.transform((data) => ({
            ...data,
            _method: 'PUT'
        })).post(route('inventory.update', editingProduct.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('inventory.store'), {
            onSuccess: () => closeModal(),
        });
    }
}

function deleteProduct(id: number) {
    if (confirm('¿Seguro que deseas eliminar este producto?')) {
        useForm({}).delete(route('inventory.destroy', id));
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Inventario', href: '/inventory' }]">
        <Head title="Inventario de Productos - Roma CRM" />

        <div class="min-h-[calc(100vh-4rem)] bg-gray-900 font-sans p-6 text-gray-100 overflow-y-auto">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white flex items-center gap-2">
                        <Package class="w-6 h-6" />
                        Inventario de Productos
                    </h1>
                    <p class="text-sm text-gray-400">Gestiona el catálogo que la IA usa para vender.</p>
                </div>
                <div class="flex gap-3">
                    <Link :href="route('crm')" class="rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-sm text-gray-100 transition-all hover:bg-gray-700">
                        ← Volver al CRM
                    </Link>
                    <button
                        @click="openModal()"
                        class="rounded-lg bg-white px-4 py-2 text-sm font-bold text-gray-900 transition-all hover:bg-gray-200 flex items-center gap-2"
                    >
                        <Plus class="w-4 h-4" />
                        Agregar Producto
                    </button>
                </div>
            </div>

            <!-- Products Grid -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div
                    v-for="product in products"
                    :key="product.id"
                    class="overflow-hidden rounded-xl border border-gray-700 bg-gray-800 shadow-sm transition-all hover:shadow-md flex flex-col justify-between"
                >
                    <div>
                        <div class="relative">
                            <img
                                :src="product.image_url || 'https://via.placeholder.com/400x300?text=Sin+Imagen'"
                                :alt="product.name"
                                class="h-48 w-full object-cover"
                            />
                            <span class="absolute left-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-xs text-white">
                                {{ product.category?.name }}
                            </span>
                        </div>
                        <div class="p-4 space-y-2">
                            <div>
                                <h3 class="font-semibold text-base leading-tight text-white">{{ product.name }}</h3>
                                <p class="text-xs text-gray-400">SKU: {{ product.sku }}</p>
                            </div>
                            <p class="text-lg font-bold text-white">S/. {{ Number(product.price).toFixed(2) }}
                                <span v-if="product.discount_percent" class="ml-2 text-xs font-semibold bg-gray-700 text-gray-300 px-2 py-0.5 rounded-full">
                                    -{{ product.discount_percent }}% OFF
                                </span>
                            </p>
                            <p class="text-xs text-gray-400 flex items-center gap-1" v-if="product.sales_count > 0">
                                <TrendingUp class="w-3 h-3" />
                                {{ product.sales_count }} vendidos
                            </p>
                            <p class="text-xs text-gray-400 line-clamp-2">{{ product.description }}</p>

                            <!-- AI Tags -->
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-for="tag in product.ai_tags"
                                    :key="tag"
                                    class="rounded-full bg-gray-700 px-2 py-0.5 text-[10px] text-gray-300 flex items-center gap-1"
                                >
                                    <Tag class="w-3 h-3" />
                                    {{ tag }}
                                </span>
                            </div>

                            <!-- Variants list with color referential images -->
                            <div class="mt-3 space-y-1">
                                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Variantes por Color:</p>
                                <div class="grid grid-cols-1 gap-1">
                                    <div
                                        v-for="(v, i) in product.variants"
                                        :key="i"
                                        class="flex items-center justify-between rounded-lg bg-gray-700 p-1 text-[10px] border border-gray-600"
                                    >
                                        <div class="flex items-center gap-2">
                                            <img
                                                :src="v.image_url || 'https://via.placeholder.com/32?text=S/I'"
                                                class="h-6 w-6 rounded object-cover border border-gray-600 bg-gray-900"
                                            />
                                            <span class="font-medium text-white">{{ v.color }} / Talla {{ v.size }}</span>
                                        </div>
                                        <span
                                            class="rounded px-1.5 py-0.5 text-[9px] font-bold"
                                            :class="v.stock > 0 ? 'bg-white/10 text-white' : 'bg-red-900/20 text-red-400'"
                                        >
                                            {{ v.stock }} disp.
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 pt-0 border-t border-gray-700 mt-3">
                        <div class="flex gap-2">
                            <button
                                @click="openModal(product)"
                                class="flex-1 rounded-lg bg-gray-700 px-3 py-1.5 text-xs font-semibold text-white transition-all hover:bg-gray-600 flex items-center justify-center gap-1"
                            >
                                <Pencil class="w-3 h-3" />
                                Editar
                            </button>
                            <button
                                @click="deleteProduct(product.id)"
                                class="flex-1 rounded-lg bg-red-900/20 px-3 py-1.5 text-xs font-semibold text-red-400 transition-all hover:bg-red-900/30 flex items-center justify-center gap-1"
                            >
                                <Trash2 class="w-3 h-3" />
                                Eliminar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="!products.length" class="col-span-full py-20 text-center text-gray-400">
                    <div class="flex justify-center mb-4">
                        <Package class="w-16 h-16 text-gray-600" />
                    </div>
                    <p class="mt-2 text-lg font-semibold text-white">Sin productos aún</p>
                    <p class="text-sm">Agrega tu primer producto para que la IA pueda venderlo.</p>
                    <button @click="openModal()" class="mt-4 rounded-lg bg-white px-6 py-2 text-sm font-bold text-gray-900 hover:bg-gray-200 flex items-center gap-2 mx-auto">
                        <Plus class="w-4 h-4" />
                        Agregar primer producto
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm p-4 animate-fade-in text-gray-100">
                <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-gray-800 border border-gray-700 shadow-2xl transition-all">
                    <div class="sticky top-0 flex items-center justify-between border-b border-gray-700 bg-gray-800 px-6 py-4 z-10">
                        <h2 class="text-xl font-bold text-white flex items-center gap-2">
                            <Package class="w-5 h-5" />
                            {{ editingProduct ? 'Editar Producto' : 'Nuevo Producto' }}
                        </h2>
                        <button @click="closeModal" class="rounded-full p-1 hover:bg-gray-700 text-gray-400 hover:text-white">
                            <X class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-5 p-6">
                        <!-- Basic Info -->
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2">
                                <label class="mb-1 block text-sm font-medium text-gray-300">Nombre del Producto *</label>
                                <input v-model="form.name" type="text" required placeholder="Ej: Polo Oversize Negro Roma" class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:ring-1 focus:ring-white focus:border-white placeholder:text-gray-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Precio (S/.) *</label>
                                <input v-model="form.price" type="number" step="0.01" required placeholder="45.00" class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:ring-1 focus:ring-white focus:border-white placeholder:text-gray-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-300">Categoría *</label>
                                <select v-model="form.category_id" required class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:ring-1 focus:ring-white focus:border-white">
                                    <option value="">Selecciona una categoría</option>
                                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                                </select>
                            </div>
                            <!-- Discount Field -->
                            <div class="col-span-2">
                                <label class="mb-1 block text-sm font-medium text-gray-300">
                                    Descuento (%) <span class="text-xs text-gray-500">— opcional, la IA lo mostrará automáticamente</span>
                                </label>
                                <div class="flex items-center gap-2">
                                    <input v-model="form.discount_percent" type="number" step="0.01" min="0" max="100" placeholder="0" class="w-32 rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:ring-1 focus:ring-white focus:border-white placeholder:text-gray-500" />
                                    <span class="text-sm text-gray-400">%</span>
                                    <span v-if="form.discount_percent && form.price" class="text-xs text-red-400 font-semibold">
                                        Precio final: S/ {{ (Number(form.price) * (1 - Number(form.discount_percent)/100)).toFixed(2) }}
                                    </span>
                                </div>
                            </div>
                            <div class="col-span-2">
                                <label class="mb-1 block text-sm font-medium text-gray-300">Descripción Detallada *</label>
                                <textarea v-model="form.description" rows="3" required placeholder="Describe los detalles de la prenda (tela, calidad, etc.)..." class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:ring-1 focus:ring-white focus:border-white placeholder:text-gray-500"></textarea>
                            </div>

                            <div class="col-span-2">
                                <label class="mb-1 block text-sm font-medium text-gray-300">
                                    Tags para la IA
                                    <span class="text-xs text-gray-500 ml-1">(separados por coma - la IA los usa para reconocer el producto)</span>
                                </label>
                                <input v-model="tagsInput" type="text" placeholder="polo, negro, oversize, algodón, live" class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:ring-1 focus:ring-white focus:border-white placeholder:text-gray-500" />
                            </div>
                        </div>

                        <!-- Nested Colors and Sizes Interface -->
                        <div class="border-t border-gray-700 pt-4">
                            <div class="mb-4 flex items-center justify-between">
                                <div>
                                    <label class="text-sm font-semibold tracking-wider text-white uppercase">Gestión de Colores, Tallas y Stock</label>
                                    <p class="text-xs text-gray-400">Agrega cada color de tu prenda, su foto y las tallas con stock disponibles.</p>
                                </div>
                                <button type="button" @click="addColor" class="text-xs text-white font-bold hover:underline bg-white/10 px-3 py-1.5 rounded-lg border border-white/20 transition-all hover:bg-white/20 flex items-center gap-1">
                                    <Plus class="w-3 h-3" />
                                    Agregar Color
                                </button>
                            </div>

                            <div class="space-y-4">
                                <div v-for="(col, i) in colors" :key="i" class="border border-gray-700 rounded-xl p-4 bg-gray-900 space-y-3 relative shadow-md">
                                    <!-- Color Name & Image Upload -->
                                    <div class="flex flex-col md:flex-row md:items-center gap-4 justify-between">
                                        <div class="flex-1">
                                            <label class="mb-1 block text-xs font-semibold text-gray-400 uppercase">Color *</label>
                                            <input v-model="col.color" type="text" required placeholder="Color (ej: Negro)" class="w-full rounded-lg border border-gray-600 bg-gray-800 px-3 py-2 text-sm text-white focus:ring-1 focus:ring-white focus:border-white placeholder:text-gray-500" />
                                        </div>
                                        <div class="flex-1">
                                            <label class="mb-1 block text-xs font-semibold text-gray-400 uppercase">Foto Referencial de este Color</label>
                                            <div class="flex items-center gap-3">
                                                <input type="file" accept="image/*" @change="handleColorFile($event, i)" class="text-xs text-gray-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-[10px] file:font-semibold file:bg-white/10 file:text-white hover:file:bg-white/20" />
                                                <img v-if="col.image_url" :src="col.image_url" class="h-9 w-9 rounded object-cover border border-gray-600 bg-gray-900" />
                                            </div>
                                        </div>
                                        <button type="button" @click="removeColor(i)" class="text-xs text-red-400 hover:text-red-300 font-semibold md:self-end md:mb-2 border border-red-500/10 px-2 py-1 rounded hover:bg-red-900/10 transition-all flex items-center gap-1">
                                            <Trash2 class="w-3 h-3" />
                                            Eliminar Color
                                        </button>
                                    </div>

                                    <!-- Inner Sizes and Stock Management -->
                                    <div class="bg-gray-800 p-3 rounded-lg border border-dashed border-gray-600 space-y-2">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Tallas y Stock disponibles:</span>
                                            <button type="button" @click="addSize(i)" class="text-xs text-white font-bold hover:underline flex items-center gap-1">
                                                <Plus class="w-3 h-3" />
                                                Agregar Talla
                                            </button>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                                            <div v-for="(sz, j) in col.sizes" :key="j" class="flex items-center gap-2 bg-gray-900 border border-gray-600 rounded-lg p-2 relative pr-7">
                                                <div class="flex-1 flex gap-2">
                                                    <input v-model="sz.size" type="text" required placeholder="Talla" class="w-20 rounded border border-gray-600 bg-gray-800 px-2 py-1 text-xs text-white focus:ring-1 focus:ring-white" />
                                                    <input v-model.number="sz.stock" type="number" min="0" required placeholder="Stock" class="w-16 rounded border border-gray-600 bg-gray-800 px-2 py-1 text-xs text-white focus:ring-1 focus:ring-white" />
                                                </div>
                                                <button type="button" @click="removeSize(i, j)" class="absolute right-2 top-2.5 text-red-400 hover:text-red-300 text-xs">
                                                    <X class="w-3 h-3" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Errors -->
                        <div v-if="form.errors && Object.keys(form.errors).length" class="rounded-lg bg-red-900/20 p-3 text-sm text-red-400 border border-red-900/30">
                            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
                        </div>

                        <!-- Submit -->
                        <div class="flex gap-3 pt-2">
                            <button type="button" @click="closeModal" class="flex-1 rounded-lg border border-gray-600 bg-gray-700 px-4 py-2 text-sm font-semibold text-white transition-all hover:bg-gray-600">
                                Cancelar
                            </button>
                            <button type="submit" :disabled="form.processing" class="flex-1 rounded-lg bg-white px-4 py-2 text-sm font-bold text-gray-900 transition-all hover:bg-gray-200 disabled:opacity-50">
                                {{ form.processing ? 'Guardando...' : (editingProduct ? 'Actualizar Producto' : 'Crear Producto') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
