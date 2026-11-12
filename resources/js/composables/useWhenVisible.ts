import { onBeforeUnmount, onMounted } from 'vue';

export function useWhenVisible(): (fn: () => void) => void {
    let waiting: (() => void) | null = null;

    function onChange(): void {
        if (document.visibilityState !== 'visible' || waiting === null) return;
        const fn = waiting;
        waiting = null;
        fn();
    }

    onMounted(() => document.addEventListener('visibilitychange', onChange));
    onBeforeUnmount(() => {
        waiting = null;
        document.removeEventListener('visibilitychange', onChange);
    });

    return (fn) => {
        if (document.visibilityState === 'visible') {
            fn();
        } else {
            waiting = fn;
        }
    };
}
