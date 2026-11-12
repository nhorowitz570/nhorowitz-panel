<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import {
    SolarClock,
    SolarGitBranch,
    SolarPlay,
    SolarSiren,
    SolarWorkflow as WorkflowIcon,
} from '@/lib/solarIcons';
import { computed, onMounted, ref } from 'vue';
import type { Component } from 'vue';
import { toast } from '@/lib/toast';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { EVENTS } from '@/components/workflows/kinds';
import type { Graph, Options } from '@/components/workflows/kinds';
import TemplatePicker from '@/components/workflows/TemplatePicker.vue';
import WorkflowPreview from '@/components/workflows/WorkflowPreview.vue';
import type { Template } from '@/components/workflows/TemplatePicker.vue';
import PlanLock from '@/components/workspace/PlanLock.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { appButtonClasses } from '@/lib/appButton';
import type { Workspace, WorkspaceAccess } from '@/types';

type WorkflowRow = {
    id: number;
    name: string;
    description: string | null;
    enabled: boolean;
    trigger: string;
    rules: Record<string, string | number | null>;
    steps: number;
    lastRun: { state: string; at: string } | null;
    lastRunAt: string | null;
    runs7d: number;
    failed7d: number;
    nextRunAt: string | null;
};

const props = defineProps<{
    workspace: Workspace;
    workflows: WorkflowRow[];
    templates: Template[];
    planAllows: boolean;
    preview: { graph: Graph; options: Options } | null;
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const picking = ref(false);
const creating = ref<string | null>(null);

onMounted(() => {
    if (
        new URLSearchParams(window.location.search).has('new') &&
        canManage.value &&
        props.planAllows &&
        props.workflows.length
    ) {
        picking.value = true;
    }
});

function create(key: string): void {
    creating.value = key;
    router.post(
        '/workflows',
        { template: key },
        {
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Couldn’t create it.'),
                ),
            onFinish: () => (creating.value = null),
        },
    );
}

function triggerLabel(row: WorkflowRow): string {
    const event = EVENTS.find((e) => e.id === row.trigger);
    if (row.trigger === 'schedule') {
        const every = row.rules.every;

        return every
            ? every === 'hour'
                ? trans('Every hour')
                : trans(
                      every === 'week'
                          ? 'Every week at :time UTC'
                          : 'Every day at :time UTC',
                      { time: String(row.rules.at ?? '03:00') },
                  )
            : trans('On a schedule');
    }

    return event?.label ?? row.trigger;
}

function ago(iso: string | null): string {
    if (!iso) return trans('Never');
    const s = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 1000),
    );
    if (s < 60) return trans('just now');
    if (s < 3600)
        return trans(':floor min ago', { floor: String(Math.floor(s / 60)) });
    if (s < 86400)
        return trans(':count h ago', { count: String(Math.floor(s / 3600)) });

    return trans(':count d ago', { count: String(Math.floor(s / 86400)) });
}

const runTone: Record<string, string> = {
    running: 'bg-(--ds-blue)',
    succeeded: 'bg-(--ds-green)',
    failed: 'bg-(--ds-red)',
    cancelled: 'bg-(--ds-text-faint)',
};

type Group = 'deploys' | 'incidents' | 'scheduled' | 'manual';

const groups: {
    key: Group;
    label: string;
    icon: Component;
    tone: string;
    triggers: string[];
}[] = [
    {
        key: 'deploys',
        label: trans('Deploys'),
        icon: SolarGitBranch,
        tone: 'text-(--ds-text)',
        triggers: ['git.push', 'deploy.finished', 'webhook'],
    },
    {
        key: 'incidents',
        label: trans('Incidents'),
        icon: SolarSiren,
        tone: 'text-(--ds-amber)',
        triggers: [
            'service.down',
            'service.crash_loop',
            'monitor.down',
            'monitor.up',
            'machine.offline',
            'machine.disk_full',
            'backup.failed',
        ],
    },
    {
        key: 'scheduled',
        label: trans('Scheduled'),
        icon: SolarClock,
        tone: 'text-(--ds-blue)',
        triggers: ['schedule'],
    },
    {
        key: 'manual',
        label: trans('By hand'),
        icon: SolarPlay,
        tone: 'text-(--ds-text-muted)',
        triggers: ['manual'],
    },
];

function groupOf(row: WorkflowRow) {
    return (
        groups.find((group) => group.triggers.includes(row.trigger)) ??
        groups[3]
    );
}

const shownGroups = computed(() =>
    groups.filter((group) =>
        props.workflows.some((row) => groupOf(row).key === group.key),
    ),
);

const filter = ref<Group | null>(null);

const shown = computed(() =>
    filter.value === null
        ? props.workflows
        : props.workflows.filter((row) => groupOf(row).key === filter.value),
);
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Workflows')" />

    <WorkspacePage :title="$t('Workflows')">
        <template #description>
            {{
                ' ' +
                $t(
                    'Deploys, restarts, checks and alerts, drawn as steps and run for you: when CI pushes, when something breaks, or on a schedule.',
                ) +
                ' '
            }}
        </template>

        <PlanLock
            :locked="!planAllows"
            feature="Workflows"
            plan="pro"
            :description="
                $t(
                    'Deploy when CI passes, restart what falls over and tell the team, drawn as steps and run for you.',
                )
            "
        >
            <WorkflowPreview
                v-if="preview"
                :graph="preview.graph"
                :options="preview.options"
            />
            <div v-else-if="!workflows.length" class="flex flex-col gap-5">
                <div
                    class="ds-tile flex flex-col items-center px-6 pt-12 pb-8 text-center"
                >
                    <div class="ds-icon-chip" aria-hidden="true">
                        <WorkflowIcon class="size-4.5" :stroke-width="2" />
                    </div>
                    <h2 class="mt-5 text-base/6 font-semibold">
                        {{ ' ' + $t('Automate what you do by hand') + ' ' }}
                    </h2>
                    <p
                        class="mt-1.5 max-w-md text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Pick a starting point for how you work. Each one opens ready to use with your machines filled in; change anything you like, and nothing runs until you turn it on.',
                            ) +
                            ' '
                        }}
                    </p>
                </div>
                <TemplatePicker
                    :templates="templates"
                    :disabled="!canManage || !planAllows"
                    :creating="creating"
                    @pick="create"
                />
            </div>

            <div v-else class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-2">
                    <div
                        class="ds-segments"
                        role="group"
                        :aria-label="$t('Show')"
                    >
                        <button
                            type="button"
                            :aria-pressed="filter === null"
                            @click="filter = null"
                        >
                            {{ $t('All') }}
                        </button>
                        <button
                            v-for="group in shownGroups"
                            :key="group.key"
                            type="button"
                            :aria-pressed="filter === group.key"
                            @click="filter = group.key"
                        >
                            {{ group.label }}
                        </button>
                    </div>
                    <button
                        v-if="canManage && planAllows"
                        type="button"
                        class="ml-auto"
                        :class="appButtonClasses('primary')"
                        @click="picking = true"
                    >
                        <Plus class="size-3.5" /> {{ $t('New workflow') + ' ' }}
                    </button>
                </div>

                <ul
                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                    :aria-label="$t('Workflows')"
                >
                    <li v-for="w in shown" :key="w.id">
                        <Link
                            :href="`/workflows/${w.id}`"
                            class="flex h-full min-h-38 flex-col rounded-[12px] bg-(--ds-card) p-4 shadow-[inset_0_0_0_1px_var(--ds-border)] transition-colors hover:bg-(--ds-hover)"
                            :class="focusRing"
                        >
                            <span
                                class="truncate text-[15px]/5 font-medium tracking-[-0.01em]"
                                :class="
                                    w.enabled
                                        ? 'text-(--ds-text)'
                                        : 'text-(--ds-text-faint)'
                                "
                                >{{ w.name }}</span
                            >
                            <span
                                class="mt-1 line-clamp-2 text-[13px]/5 text-(--ds-text-muted)"
                            >
                                {{
                                    w.description ||
                                    `${w.steps} ${w.steps === 1 ? $t('step') : $t('steps')}`
                                }}
                            </span>
                            <span
                                class="mt-auto flex items-end justify-between gap-3 pt-5"
                            >
                                <span
                                    class="flex min-w-0 flex-col gap-0.5 text-[12.5px]/4"
                                >
                                    <span
                                        class="truncate text-(--ds-text-secondary)"
                                        >{{ triggerLabel(w) }}</span
                                    >
                                    <span
                                        class="flex items-center gap-1.5 truncate text-(--ds-text-faint) tabular-nums"
                                    >
                                        <span
                                            v-if="w.lastRun"
                                            class="size-1.5 shrink-0 rounded-full"
                                            :class="runTone[w.lastRun.state]"
                                        />
                                        <template v-if="!w.enabled">{{
                                            $t('Off')
                                        }}</template>
                                        <template v-else>{{
                                            ago(w.lastRunAt)
                                        }}</template>
                                        <span
                                            v-if="w.failed7d"
                                            class="text-(--ds-red)"
                                            >{{
                                                $t('· :failed7d failed', {
                                                    failed7d: String(
                                                        w.failed7d,
                                                    ),
                                                })
                                            }}</span
                                        >
                                    </span>
                                </span>
                                <component
                                    :is="groupOf(w).icon"
                                    class="size-7 shrink-0"
                                    :class="groupOf(w).tone"
                                    :stroke-width="1.6"
                                    aria-hidden="true"
                                />
                            </span>
                        </Link>
                    </li>
                </ul>
            </div>
        </PlanLock>

        <Dialog v-model:open="picking">
            <DialogContent
                class="workspace-panel ds-modal flex max-h-[min(86vh,760px)] flex-col gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-2xl"
            >
                <div class="flex shrink-0 flex-col gap-1 px-5 pt-5 pb-3">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('New workflow')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Start from one of these and change anything you like. It stays off until you turn it on.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div class="flex min-h-0 flex-1 flex-col px-5 pb-5">
                    <TemplatePicker
                        :templates="templates"
                        :creating="creating"
                        compact
                        @pick="create"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>
