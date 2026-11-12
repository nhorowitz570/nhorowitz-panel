<script setup lang="ts">
type Props = {
    label: string;
    inputId?: string;
    hint?: string;
    wide?: boolean;
};

defineProps<Props>();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2" :class="{ 'md:col-span-2': wide }">
        <label
            v-if="inputId"
            :for="inputId"
            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
        >
            {{ label }}
        </label>
        <span v-else class="text-[13px]/5 font-medium text-(--ds-text-muted)">
            {{ label }}
        </span>
        <slot />
        <p v-if="hint" class="text-[13px]/5 text-(--ds-text-faint)">
            {{ hint }}
        </p>
    </div>
</template>
