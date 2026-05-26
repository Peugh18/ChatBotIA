<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Tag, Plus, Pencil, Trash2, X, Package } from 'lucide-vue-next';

interface Category {
    id: number;
    name: string;
    slug: string;
    products_count: number;
}

const props = defineProps<{
    categories: Category[];
}>();

const newCategoryName = ref('');
const editingCategory = ref<Category | null>(null);

function saveCategory() {
    if (!newCategoryName.value) return;
    
    if (editingCategory.value) {
        router.put(route('categories.update', editingCategory.value.id), {
            name: newCategoryName.value
        }, {
            onSuccess: () => {
                editingCategory.value = null;
                newCategoryName.value = '';
            }
        });
    } else {
        router.post(route('categories.store'), {
            name: newCategoryName.value
        }, {
            onSuccess: () => {
                newCategoryName.value = '';
            }
        });
    }
}

function editCategory(c: Category) {
    editingCategory.value = c;
    newCategoryName.value = c.name;
}

function cancelEdit() {
    editingCategory.value = null;
    newCategoryName.value = '';
}

function deleteCategory(c: Category) {
    if (c.products_count > 0) {
        alert('No se puede eliminar porque tiene productos asociados.');
        return;
    }
    if (confirm(`¿Eliminar la categoría ${c.name}?`)) {
        router.delete(route('categories.destroy', c.id));
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Categorías', href: '/categories' }]">
        <Head title="Categorías" />

        <div class="flex h-[calc(100vh-4rem)] bg-gray-900 font-sans p-6 overflow-hidden text-gray-100">
            <div class="mx-auto flex h-full w-full max-w-5xl flex-col bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                
                <div class="p-6 border-b border-gray-700 bg-gray-800 flex justify-between items-center">
                    <h1 class="text-xl font-bold text-white flex items-center gap-2">
                        <Tag class="w-6 h-6" />
                        Gestión de Categorías
                    </h1>
                </div>

                <div class="flex flex-col md:flex-row h-full overflow-hidden">
                    <!-- Formulario -->
                    <div class="w-full md:w-1/3 p-6 border-r border-gray-700 overflow-y-auto bg-gray-900">
                        <h2 class="text-lg font-semibold mb-4 text-white flex items-center gap-2">
                            <Plus v-if="!editingCategory" class="w-5 h-5" />
                            <Pencil v-else class="w-5 h-5" />
                            {{ editingCategory ? 'Editar Categoría' : 'Nueva Categoría' }}
                        </h2>

                        <form @submit.prevent="saveCategory" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Nombre</label>
                                <input v-model="newCategoryName" type="text" class="w-full rounded-md border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white focus:border-gray-400 focus:ring-gray-400" required>
                            </div>

                            <div class="flex gap-2">
                                <button type="submit" class="flex-1 rounded-md bg-white px-4 py-2 text-sm font-bold text-gray-900 hover:bg-gray-200 transition-colors flex items-center justify-center gap-1">
                                    <Plus v-if="!editingCategory" class="w-4 h-4" />
                                    <Pencil v-else class="w-4 h-4" />
                                    {{ editingCategory ? 'Actualizar' : 'Guardar' }}
                                </button>
                                <button v-if="editingCategory" @click="cancelEdit" type="button" class="px-4 py-2 text-sm font-medium text-gray-400 hover:text-white border border-gray-600 rounded-md flex items-center gap-1">
                                    <X class="w-4 h-4" />
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Lista -->
                    <div class="w-full md:w-2/3 p-6 bg-gray-800 overflow-y-auto">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div v-for="cat in categories" :key="cat.id" class="border border-gray-700 rounded-lg p-4 flex justify-between items-start hover:border-gray-500 transition-colors bg-gray-900">
                                <div>
                                    <h3 class="font-semibold text-white">{{ cat.name }}</h3>
                                    <p class="text-xs text-gray-400 mt-1 flex items-center gap-1">
                                        <Package class="w-3 h-3" />
                                        {{ cat.products_count }} productos asociados
                                    </p>
                                </div>
                                <div class="flex gap-2">
                                    <button @click="editCategory(cat)" class="text-gray-400 hover:text-white transition-colors" title="Editar">
                                        <Pencil class="w-4 h-4" />
                                    </button>
                                    <button @click="deleteCategory(cat)" class="text-gray-400 hover:text-red-400 transition-colors" title="Eliminar">
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
