<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <div class="flex items-center gap-4">
        <img src="/img/LogoRomaStore.png" alt="Roma Logo" class="h-16 w-16 object-contain" />
        <div class="grid flex-1 text-left">
            <span class="mb-0.5 truncate font-bold leading-none text-white text-2xl"></span>
        </div>
    </div>
</template>
