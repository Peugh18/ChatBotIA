<script setup lang="ts">
import { computed } from 'vue';

interface Props {
  status: string;
}

const props = defineProps<Props>();

// Map status to Tailwind color classes (using our new primary/accent colors)
const colorClass = computed(() => {
  switch (props.status.toUpperCase()) {
    case 'NUEVO':
    case 'INTERESADO':
    case 'ALTA':
      return 'bg-primary text-primary-foreground';
    case 'PENDIENTE':
    case 'ESPERANDO PAGO':
    case 'VERIFICARYAPE':
      return 'bg-accent text-accent-foreground';
    case 'RECHAZADO':
    case 'CANCELADO':
      return 'bg-destructive text-destructive-foreground';
    default:
      return 'bg-muted text-muted-foreground';
  }
});
</script>

<template>
  <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', colorClass]">
    {{ props.status }}
  </span>
</template>

<style scoped>
/* optional: add subtle shadow or animation */
</style>
