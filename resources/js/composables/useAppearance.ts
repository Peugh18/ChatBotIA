import { onMounted, ref } from 'vue';

type Appearance = 'light' | 'dark' | 'system';

export function updateTheme(value: Appearance) {
    document.documentElement.classList.add('dark');
}

export function initializeTheme() {
    document.documentElement.classList.add('dark');
}

export function useAppearance() {
    const appearance = ref<Appearance>('dark');

    onMounted(() => {
        initializeTheme();
    });

    function updateAppearance(value: Appearance) {
        appearance.value = 'dark';
        updateTheme('dark');
    }

    return {
        appearance,
        updateAppearance,
    };
}
