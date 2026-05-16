<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

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

        <div class="flex h-[calc(100vh-4rem)] bg-[#0b141a] font-sans p-6 overflow-hidden text-[#e9edef]">
            <div class="mx-auto flex h-full w-full max-w-5xl flex-col bg-[#111b21] rounded-xl shadow-sm border border-[#202c33] overflow-hidden">
                
                <div class="p-6 border-b border-[#202c33] bg-[#202c33]/50 flex justify-between items-center">
                    <h1 class="text-xl font-bold text-[#e9edef]">Gestión de Categorías</h1>
                </div>

                <div class="flex flex-col md:flex-row h-full overflow-hidden">
                    <!-- Formulario -->
                    <div class="w-full md:w-1/3 p-6 border-r border-[#202c33] overflow-y-auto">
                        <h2 class="text-lg font-semibold mb-4 text-[#e9edef]">
                            {{ editingCategory ? 'Editar Categoría' : 'Nueva Categoría' }}
                        </h2>

                        <form @submit.prevent="saveCategory" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-[#8696a0] mb-1">Nombre</label>
                                <input v-model="newCategoryName" type="text" class="w-full rounded-md border border-[#202c33] bg-[#0b141a] px-3 py-2 text-sm text-[#e9edef] focus:border-[#00a884] focus:ring-[#00a884]" required>
                            </div>

                            <div class="flex gap-2">
                                <button type="submit" class="flex-1 rounded-md bg-[#00a884] px-4 py-2 text-sm font-bold text-[#111b21] hover:bg-[#06cf9c] transition-colors">
                                    {{ editingCategory ? 'Actualizar' : 'Guardar' }}
                                </button>
                                <button v-if="editingCategory" @click="cancelEdit" type="button" class="px-4 py-2 text-sm font-medium text-[#8696a0] hover:text-[#e9edef] border border-[#202c33] rounded-md">
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Lista -->
                    <div class="w-full md:w-2/3 p-6 bg-[#111b21] overflow-y-auto">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div v-for="cat in categories" :key="cat.id" class="border border-[#202c33] rounded-lg p-4 flex justify-between items-start hover:border-[#00a884]/50 transition-colors bg-[#202c33]/30">
                                <div>
                                    <h3 class="font-semibold text-[#e9edef]">{{ cat.name }}</h3>
                                    <p class="text-xs text-[#8696a0] mt-1">{{ cat.products_count }} productos asociados</p>
                                </div>
                                <div class="flex gap-2">
                                    <button @click="editCategory(cat)" class="text-blue-500 hover:text-blue-600" title="Editar">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    </button>
                                    <button @click="deleteCategory(cat)" class="text-red-500 hover:text-red-600" title="Eliminar">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
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
