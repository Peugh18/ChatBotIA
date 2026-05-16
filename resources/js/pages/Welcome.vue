<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Bienvenido" />
    <div class="flex min-h-screen flex-col items-center justify-center bg-[#111b21] text-[#e9edef]">
        <div class="text-center">
            <div class="mb-6 flex justify-center opacity-20">
                <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" alt="WA" class="w-24 grayscale" />
            </div>
            <h1 class="mb-2 text-4xl font-light">WhatsApp CRM Roma</h1>
            <p class="mb-8 text-[#8696a0]">Gestiona tus ventas con IA de forma automatizada.</p>
            <div class="flex gap-4 justify-center">
                <Link :href="route('login')" class="rounded-lg bg-[#00a884] px-6 py-2 font-semibold text-black hover:bg-[#06cf9c] transition-all">
                    Ingresar
                </Link>
                <Link :href="route('register')" class="rounded-lg bg-[#202c33] px-6 py-2 font-semibold text-white hover:bg-[#2a3942] transition-all">
                    Registrarse
                </Link>
            </div>
        </div>
    </div>
</template>
