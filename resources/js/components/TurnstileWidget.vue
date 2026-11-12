<script setup lang="ts">
import { onBeforeUnmount, onMounted, useTemplateRef } from 'vue';

const props = defineProps<{ siteKey: string }>();

type TurnstileApi = {
    render: (el: HTMLElement, options: Record<string, unknown>) => string;
    reset: (id?: string) => void;
    remove: (id: string) => void;
};

declare global {
    interface Window {
        turnstile?: TurnstileApi;
        onServerOSTurnstile?: () => void;
    }
}

const SCRIPT =
    'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onServerOSTurnstile';

const box = useTemplateRef<HTMLDivElement>('box');
let widget: string | null = null;

function render(): void {
    if (!window.turnstile || !box.value || widget !== null) return;
    widget = window.turnstile.render(box.value, {
        sitekey: props.siteKey,
        theme: 'auto',
        size: 'flexible',
        appearance: 'interaction-only',
    });
}

onMounted(() => {
    if (window.turnstile) {
        render();
        return;
    }
    window.onServerOSTurnstile = render;
    if (!document.querySelector(`script[src="${SCRIPT}"]`)) {
        const script = document.createElement('script');
        script.src = SCRIPT;
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }
});

onBeforeUnmount(() => {
    if (widget !== null) window.turnstile?.remove(widget);
});

defineExpose({
    reset: () => {
        if (widget !== null) window.turnstile?.reset(widget);
    },
});
</script>

<template>
    <div ref="box" />
</template>
