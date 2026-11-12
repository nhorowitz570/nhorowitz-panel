import { onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import type { Ref } from 'vue';

export function useStepFrame(refName: string): {
    height: Ref<string | undefined>;
    freeze: () => void;
    unfreeze: () => void;
} {
    const inner = useTemplateRef<HTMLElement>(refName);
    const height = ref<string | undefined>(undefined);
    let frozen = false;
    let observer: ResizeObserver | undefined;

    watch(inner, (el) => {
        observer?.disconnect();
        height.value = undefined;

        if (el && 'ResizeObserver' in window) {
            observer = new ResizeObserver(([entry]) => {
                if (!frozen && entry) {
                    height.value = `${entry.contentRect.height}px`;
                }
            });
            observer.observe(el);
        }
    });

    onBeforeUnmount(() => observer?.disconnect());

    return {
        height,
        freeze: () => (frozen = true),
        unfreeze: () => (frozen = false),
    };
}
