<script setup lang="ts">
import { ref } from 'vue';
import type { Graph, Options } from './kinds';
import {
    createWorkflowEditor,
    provideWorkflowEditor,
} from './useWorkflowEditor';
import WorkflowCanvas from './WorkflowCanvas.vue';

const { graph, options } = defineProps<{ graph: Graph; options: Options }>();

const editor = createWorkflowEditor(graph, ref(false));
provideWorkflowEditor(editor);
</script>

<template>
    <div
        class="relative flex h-[calc(100dvh-240px)] min-h-[520px] flex-col overflow-hidden rounded-[12px] border border-(--ds-border)"
    >
        <WorkflowCanvas
            :options="options"
            :webhook-url="null"
            :two-factor="false"
            :problems="{}"
        />
    </div>
</template>
