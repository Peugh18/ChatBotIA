import { onMounted, ref } from 'vue';

type Appearance = 'light' | 'dark' | 'system';

function applyTheme(value: Appearance) {
    const isDark = value === 'dark' || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', isDark);
}

export function updateTheme(value: Appearance) {
    applyTheme(value);
}

export function initializeTheme() {
    const saved = (localStorage.getItem('appearance') as Appearance) || 'light';
    applyTheme(saved);
}

export function useAppearance() {
    const appearance = ref<Appearance>('light');

    onMounted(() => {
        const saved = (localStorage.getItem('appearance') as Appearance) || 'light';
        appearance.value = saved;
        applyTheme(saved);
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;
        localStorage.setItem('appearance', value);
        applyTheme(value);
    }

    return {
        appearance,
        updateAppearance,
    };
}