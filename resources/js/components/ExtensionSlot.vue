<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    name: string;
    parts: Record<string, Component>;
}>();

const SLOT_FILE = /\/resources\/js\/slots\/(.+)\/([^/]+)\.vue$/;

const found = computed(() =>
    Object.entries(props.parts)
        .map(([path, component]) => {
            const match = SLOT_FILE.exec(path);

            return match?.[1] === props.name
                ? { key: path, file: match[2], component }
                : null;
        })
        .filter((part) => part !== null)
        .sort((a, b) => a.file.localeCompare(b.file)),
);
</script>

<template>
    <component
        :is="part.component"
        v-for="part in found"
        :key="part.key"
        v-bind="$attrs"
    />
</template>
