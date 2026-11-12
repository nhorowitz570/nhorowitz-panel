<script setup lang="ts">
import { computed } from 'vue';

type Props = {
    value: number;
    dots?: number;
    warnAt?: number;
};

const { value, dots = 16, warnAt = 80 } = defineProps<Props>();

const lit = computed(() =>
    value <= 0
        ? 0
        : Math.max(1, Math.round((Math.min(value, 100) / 100) * dots)),
);

const warn = computed(() => value >= warnAt);

function opacity(index: number): number {
    return 0.35 + 0.65 * ((index + 1) / Math.max(lit.value, 1));
}
</script>

<template>
    <span class="flex items-center gap-[3px]" aria-hidden="true">
        <span
            v-for="index in dots"
            :key="index"
            class="size-[4px] shrink-0 rounded-full"
            :class="
                index <= lit
                    ? warn
                        ? 'bg-(--ds-amber)'
                        : 'bg-(--ds-text)'
                    : 'bg-(--ds-border-strong)'
            "
            :style="index <= lit ? { opacity: opacity(index - 1) } : undefined"
        />
    </span>
</template>
