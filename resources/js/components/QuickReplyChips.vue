<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

interface QuickReply {
  id: number;
  shortcut: string;
  title: string;
  body: string;
}

const props = defineProps<{ quickReplies: QuickReply[] }>();

// Show top 5 most used quick replies (or all if less)
const displayed = computed(() => props.quickReplies.slice(0, 5));
</script>

<template>
  <div class="fixed bottom-4 right-4 flex gap-2 z-50">
    <Button
      v-for="qr in displayed"
      :key="qr.id"
      variant="primary"
      size="sm"
      @click="$emit('use', qr)"
    >
      {{ qr.title }}
    </Button>
  </div>
</template>
