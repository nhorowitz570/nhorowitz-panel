<script setup lang="ts">
import type { Component } from 'vue';
import { useId } from 'vue';

type Props = {
    title?: string;
    description?: string;
    icon?: Component;
    danger?: boolean;
    padded?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    danger: false,
    padded: true,
});

const headingId = useId();
</script>

<template>
    <section
        class="ds-tile flex min-w-0 flex-col"
        :class="{ 'ds-tile-danger': danger }"
        :aria-labelledby="props.title ? headingId : undefined"
    >
        <template v-if="title || $slots.head">
            <div
                class="ds-tile-head flex min-h-12 flex-wrap items-center gap-x-2.5 gap-y-2 px-4 py-2.5"
            >
                <span v-if="icon" class="ds-icon-chip" aria-hidden="true">
                    <component :is="icon" :stroke-width="1.75" />
                </span>
                <slot name="head">
                    <div class="flex min-w-0 grow basis-48 flex-col">
                        <h2
                            :id="headingId"
                            class="text-[14px]/5 font-medium"
                            :class="{ 'text-(--ds-red)': danger }"
                        >
                            {{ title }}
                        </h2>
                        <p
                            v-if="description"
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{ description }}
                        </p>
                    </div>
                </slot>
                <div
                    v-if="$slots.actions"
                    class="ml-auto flex shrink-0 flex-wrap items-center gap-2"
                >
                    <slot name="actions" />
                </div>
            </div>
            <div class="ds-tile-divider" />
        </template>

        <div class="ds-tile-body flex grow flex-col" :class="{ 'p-4': padded }">
            <slot />
        </div>

        <template v-if="$slots.footer">
            <div class="ds-tile-divider" />
            <div
                class="ds-tile-head flex flex-wrap items-center justify-end gap-2 px-4 py-2.5"
            >
                <slot name="footer" />
            </div>
        </template>
    </section>
</template>
