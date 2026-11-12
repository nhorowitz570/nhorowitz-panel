<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { PanelLeft, PinOff, Search } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import type { Component } from 'vue';
import AccountMenu from '@/components/workspace/AccountMenu.vue';
import WorkspaceMenu from '@/components/workspace/WorkspaceMenu.vue';
import { useWorkspaceNav } from '@/components/workspace/workspaceNav';
import type { WorkspaceNavItem } from '@/components/workspace/workspaceNav';
import { useAssistant } from '@/composables/useAssistant';
import { usePinnedWorkspaces } from '@/composables/usePinnedWorkspaces';
import { useWorkspaceSwitch } from '@/composables/useWorkspaceSwitch';
import { SolarAskAi } from '@/lib/solarIcons';
import { workspaceInitial, workspaceTileColor } from '@/lib/workspaceTile';
import type { Workspace, WorkspaceSummary } from '@/types';

type Props = {
    collapsed?: boolean;
    collapsible?: boolean;
};

const { collapsed = false, collapsible = false } = defineProps<Props>();

const emit = defineEmits<{
    toggle: [];
    search: [];
    assistant: [];
    whatsNew: [];
}>();

const page = usePage<{ workspace?: Workspace }>();
const items = useWorkspaceNav();
const assistant = useAssistant();

function openAssistant(): void {
    assistant.toggle();
    emit('assistant');
}

const { pinned, toggle: unpin } = usePinnedWorkspaces();
const { switchingTo, switchTo } = useWorkspaceSwitch();

function openPinned(workspace: WorkspaceSummary): void {
    if (workspace.id !== page.props.workspace?.id) {
        switchTo(workspace);
    }
}

type Row = {
    key: string;
    label: string;
    icon: Component;
    item?: WorkspaceNavItem;
};

const askRow: Row = {
    key: 'ask',
    label: 'Ask AI',
    icon: SolarAskAi,
};

const toRow = (item: WorkspaceNavItem): Row => ({
    key: item.label,
    label: item.label,
    icon: item.icon,
    item,
});

const ORDER = [
    'Home',
    'Activity',
    'Machines',
    'Services',
    'Projects',
    'Updates',
    'Monitors',
    'Logs',
    'Backups',
    'Workflows',
    'Members',
    'Billing',
    'Settings',
];

function rank(item: WorkspaceNavItem): number {
    const index = ORDER.findIndex((label) => trans(label) === item.label);

    return index === -1 ? ORDER.indexOf('Settings') - 0.5 : index;
}

type Group = { key: string; label: string | null; rows: Row[] };

const groups = computed<Group[]>(() => {
    const rowsIn = (group: WorkspaceNavItem['group']): Row[] =>
        items.value
            .filter((item) => item.group === group)
            .sort((a, b) => rank(a) - rank(b))
            .map(toRow);

    return [
        {
            key: 'start',
            label: null,
            rows: [...rowsIn('start'), askRow],
        },
        { key: 'servers', label: 'Servers', rows: rowsIn('servers') },
        { key: 'operations', label: 'Operations', rows: rowsIn('operations') },
        { key: 'workspace', label: 'Workspace', rows: rowsIn('workspace') },
    ].filter((group) => group.rows.length > 0);
});

const GROUPS_KEY = 'workspace-sidebar-closed';
const closedGroups = ref<string[]>([]);

function isOpen(key: string): boolean {
    return collapsed || !closedGroups.value.includes(key);
}

function toggleGroup(key: string): void {
    closedGroups.value = isOpen(key)
        ? [...closedGroups.value, key]
        : closedGroups.value.filter((k) => k !== key);

    try {
        window.localStorage.setItem(
            GROUPS_KEY,
            JSON.stringify(closedGroups.value),
        );
    } catch {}
}

const allRows = computed(() =>
    groups.value
        .filter((group) => isOpen(group.key))
        .flatMap((group) => group.rows),
);

type Box = { top: number; left: number; width: number; height: number };

const track = useTemplateRef<HTMLElement>('track');
const itemEls = new Map<string, HTMLElement>();
const boxes = ref<Record<string, Box>>({});
const pending = ref<string | null>(null);
const hovered = ref<string | null>(null);
const activeReady = ref(false);
const hoverGlides = ref(false);

function setItemEl(key: string, el: unknown): void {
    if (el instanceof HTMLElement) {
        itemEls.set(key, el);
    } else {
        itemEls.delete(key);
    }
}

function measure(): void {
    const next: Record<string, Box> = {};

    for (const [key, el] of itemEls) {
        next[key] = {
            top: el.offsetTop,
            left: el.offsetLeft,
            width: el.offsetWidth,
            height: el.offsetHeight,
        };
    }

    boxes.value = next;
}

const currentKey = computed(() => {
    if (pending.value) {
        return pending.value;
    }

    if (assistant.open.value) {
        return askRow.key;
    }

    const row = allRows.value.find((row) => row.item?.active);
    const child = collapsed
        ? undefined
        : row?.item?.children?.find((child) => child.active);

    return child ? `${row?.key}/${child.key}` : row?.key;
});

function pillStyle(key: string | null | undefined): Record<string, string> {
    const box = key ? boxes.value[key] : undefined;

    if (!box) {
        return { opacity: '0' };
    }

    return {
        width: `${box.width}px`,
        height: `${box.height}px`,
        transform: `translate(${box.left}px, ${box.top}px)`,
    };
}

function enter(key: string): void {
    hoverGlides.value = hovered.value !== null;
    hovered.value = key;
}

function leave(): void {
    hovered.value = null;
}

let observer: ResizeObserver | undefined;
let stopFinish: (() => void) | undefined;

onMounted(async () => {
    try {
        const saved: unknown = JSON.parse(
            window.localStorage.getItem(GROUPS_KEY) ?? '[]',
        );
        closedGroups.value = Array.isArray(saved)
            ? saved.filter((k): k is string => typeof k === 'string')
            : [];
    } catch {}

    await nextTick();
    measure();
    requestAnimationFrame(() => (activeReady.value = true));

    if (track.value && 'ResizeObserver' in window) {
        observer = new ResizeObserver(measure);
        observer.observe(track.value);
    }

    stopFinish = router.on('finish', () => {
        pending.value = null;
    });
});

onBeforeUnmount(() => {
    observer?.disconnect();
    stopFinish?.();
});

watch(
    () => [collapsed, allRows.value.length, closedGroups.value.length],
    async () => {
        await nextTick();
        measure();
    },
);

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';

const rowClass =
    'sidebar-item flex h-8 w-full items-center gap-2 rounded-[10px] text-[14px]/5 tracking-[-0.006em] transition-colors duration-200';
</script>

<template>
    <div class="flex h-full flex-col font-ui text-(--ds-text)">
        <div
            v-if="!collapsed"
            class="flex h-8 shrink-0 items-center gap-0.5 pr-0.5 pl-3"
        >
            <a
                :href="page.props.appUrl"
                class="mr-auto flex min-w-0 items-center gap-2 rounded-md"
                :class="focusRing"
            >
                <img
                    src="/images/landing/serveros-logo.svg"
                    alt=""
                    class="size-[18px] shrink-0 object-contain invert dark:invert-0"
                />
                <span
                    class="truncate text-[15px]/5 font-semibold tracking-[-0.02em]"
                    >{{ page.props.name }}</span
                >
            </a>
            <button
                type="button"
                class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-[8px] text-(--ds-text-muted) transition-colors duration-150 hover:bg-(--ds-hover) hover:text-(--ds-text)"
                :class="focusRing"
                :aria-label="$t('Search')"
                :title="$t('Search (⌘K)')"
                aria-haspopup="dialog"
                @click="emit('search')"
            >
                <Search class="size-4" :stroke-width="1.75" />
            </button>
            <button
                v-if="collapsible"
                type="button"
                class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-[8px] text-(--ds-text-muted) transition-colors duration-150 hover:bg-(--ds-hover) hover:text-(--ds-text)"
                :class="focusRing"
                :aria-label="$t('Collapse sidebar')"
                aria-expanded="true"
                @click="emit('toggle')"
            >
                <PanelLeft class="mi-panel size-4" :stroke-width="1.6" />
            </button>
        </div>

        <div v-else class="flex h-8 shrink-0 items-center justify-center">
            <button
                type="button"
                class="group flex size-8 cursor-pointer items-center justify-center rounded-[8px] hover:bg-(--ds-hover)"
                :class="focusRing"
                :aria-label="$t('Expand sidebar')"
                aria-expanded="false"
                @click="emit('toggle')"
            >
                <span
                    v-if="page.props.workspace"
                    class="flex size-4.5 items-center justify-center overflow-hidden rounded-[5px] text-[10px]/3 font-semibold group-hover:hidden group-focus-visible:hidden"
                    :class="
                        workspaceTileColor(
                            (switchingTo ?? page.props.workspace).id,
                        )
                    "
                    aria-hidden="true"
                >
                    <img
                        v-if="(switchingTo ?? page.props.workspace).logoUrl"
                        :src="(switchingTo ?? page.props.workspace).logoUrl!"
                        alt=""
                        class="size-full object-cover"
                    />
                    <template v-else>{{
                        workspaceInitial(
                            (switchingTo ?? page.props.workspace).name,
                        )
                    }}</template>
                </span>
                <PanelLeft
                    class="mi-panel mi-panel-open hidden size-4 text-(--ds-text-muted) group-hover:block group-focus-visible:block"
                    :stroke-width="1.6"
                />
            </button>
        </div>

        <nav
            class="min-h-0 grow overflow-y-auto"
            :class="collapsed ? 'mt-3' : 'mt-1'"
            :aria-label="$t('Workspace')"
        >
            <div
                v-if="collapsed"
                class="mx-4 mb-3 border-t border-(--ds-divider)"
                aria-hidden="true"
            />
            <div ref="track" class="relative px-2" @mouseleave="leave">
                <span
                    class="sidebar-pill sidebar-pill--hover"
                    :class="{
                        'sidebar-pill--glide': hoverGlides,
                        'opacity-0': !hovered || hovered === currentKey,
                    }"
                    :style="pillStyle(hovered)"
                    aria-hidden="true"
                />
                <span
                    class="sidebar-pill sidebar-pill--active"
                    :class="{ 'sidebar-pill--spring': activeReady }"
                    :style="pillStyle(currentKey)"
                    aria-hidden="true"
                />

                <template v-for="(group, index) in groups" :key="group.key">
                    <div
                        v-if="index === 1 && !collapsed"
                        class="mx-1 mt-3 h-px bg-(--ds-divider)"
                        aria-hidden="true"
                    />
                    <h2 v-if="group.label && !collapsed" class="mt-4 mb-px">
                        <button
                            type="button"
                            class="flex cursor-pointer items-center gap-1.5 rounded-md px-2.5 py-1 text-[12px]/4 text-(--ds-text-faint) transition-colors hover:text-(--ds-text-secondary)"
                            :class="focusRing"
                            :aria-expanded="isOpen(group.key)"
                            @click="toggleGroup(group.key)"
                        >
                            {{ $t(group.label) }}
                            <svg
                                viewBox="0 0 8 8"
                                class="size-2 transition-transform duration-150"
                                :class="{ '-rotate-90': !isOpen(group.key) }"
                                aria-hidden="true"
                            >
                                <path d="M1 2.5h6L4 6z" fill="currentColor" />
                            </svg>
                        </button>
                    </h2>
                    <div
                        v-else-if="index > 0 && collapsed"
                        class="mx-2 my-3 border-t border-(--ds-divider)"
                        aria-hidden="true"
                    />
                    <ul
                        v-if="isOpen(group.key)"
                        class="flex flex-col gap-0.5"
                        :class="{ 'mt-2': !group.label && !collapsed }"
                    >
                        <li v-for="row in group.rows" :key="row.key">
                            <div :ref="(el) => setItemEl(row.key, el)">
                                <button
                                    v-if="row.key === 'ask'"
                                    type="button"
                                    class="cursor-pointer"
                                    :class="[
                                        rowClass,
                                        collapsed
                                            ? 'justify-center px-0'
                                            : 'px-2.5',
                                        currentKey === row.key
                                            ? 'text-(--ds-text)'
                                            : 'text-(--ds-text-secondary) hover:text-(--ds-text)',
                                        focusRing,
                                    ]"
                                    :aria-expanded="assistant.open.value"
                                    aria-controls="assistant-panel"
                                    :aria-label="
                                        collapsed ? $t('Ask AI') : undefined
                                    "
                                    :title="
                                        collapsed
                                            ? $t('Ask AI (⌘J)')
                                            : undefined
                                    "
                                    @mouseenter="enter(row.key)"
                                    @click="openAssistant"
                                >
                                    <component
                                        :is="row.icon"
                                        class="size-[15px] shrink-0 text-(--ds-text-muted)"
                                        :stroke-width="1.6"
                                    />
                                    <template v-if="!collapsed">
                                        <span class="truncate">{{
                                            $t(row.label)
                                        }}</span>
                                        <kbd
                                            class="ml-auto font-ui text-[11px]/3.5 text-(--ds-text-faint)"
                                            >⌘J</kbd
                                        >
                                    </template>
                                </button>
                                <Link
                                    v-else-if="row.item?.href"
                                    :href="row.item.href"
                                    :class="[
                                        rowClass,
                                        collapsed
                                            ? 'justify-center px-0'
                                            : 'px-2.5',
                                        currentKey === row.key
                                            ? 'text-(--ds-text)'
                                            : 'text-(--ds-text-secondary) hover:text-(--ds-text)',
                                        focusRing,
                                    ]"
                                    :aria-current="
                                        row.item.active ? 'page' : undefined
                                    "
                                    :aria-label="
                                        collapsed ? $t(row.label) : undefined
                                    "
                                    :title="
                                        collapsed ? $t(row.label) : undefined
                                    "
                                    @mouseenter="enter(row.key)"
                                    @click="pending = row.key"
                                >
                                    <component
                                        :is="row.icon"
                                        class="size-[15px] shrink-0 transition-colors duration-200"
                                        :class="
                                            currentKey === row.key
                                                ? 'text-(--ds-text)'
                                                : 'text-(--ds-text-muted)'
                                        "
                                        :stroke-width="1.6"
                                    />
                                    <span v-if="!collapsed" class="truncate">
                                        {{ $t(row.label) }}
                                    </span>
                                    <svg
                                        v-if="row.item.children && !collapsed"
                                        viewBox="0 0 8 8"
                                        class="size-2 shrink-0 text-(--ds-text-faint) transition-transform duration-150"
                                        :class="{
                                            '-rotate-90': !row.item.active,
                                        }"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="M1 2.5h6L4 6z"
                                            fill="currentColor"
                                        />
                                    </svg>
                                </Link>
                            </div>
                            <ul
                                v-if="
                                    row.item?.children &&
                                    row.item.active &&
                                    !collapsed
                                "
                                class="mt-px flex flex-col gap-px"
                            >
                                <li
                                    v-for="child in row.item.children"
                                    :key="child.key"
                                    :ref="
                                        (el) =>
                                            setItemEl(
                                                `${row.key}/${child.key}`,
                                                el,
                                            )
                                    "
                                >
                                    <Link
                                        :href="child.href"
                                        class="pr-2.5 pl-[33px]"
                                        :class="[
                                            rowClass,
                                            currentKey ===
                                            `${row.key}/${child.key}`
                                                ? 'text-(--ds-text)'
                                                : 'text-(--ds-text-muted) hover:text-(--ds-text)',
                                            focusRing,
                                        ]"
                                        :aria-current="
                                            child.active ? 'page' : undefined
                                        "
                                        @mouseenter="
                                            enter(`${row.key}/${child.key}`)
                                        "
                                        @click="
                                            pending = `${row.key}/${child.key}`
                                        "
                                    >
                                        <component
                                            :is="child.icon"
                                            class="size-[15px] shrink-0"
                                            :class="
                                                currentKey ===
                                                `${row.key}/${child.key}`
                                                    ? 'text-(--ds-text)'
                                                    : 'text-(--ds-text-muted)'
                                            "
                                            :stroke-width="1.6"
                                        />
                                        <span class="truncate">{{
                                            child.label
                                        }}</span>
                                    </Link>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </template>
            </div>

            <section
                v-if="pinned.length"
                class="mt-5 px-2"
                aria-labelledby="sidebar-pinned"
            >
                <h2
                    id="sidebar-pinned"
                    class="mb-1.5 px-2.5 text-[13px]/4 text-(--ds-text-faint)"
                    :class="{ 'sr-only': collapsed }"
                >
                    {{ $t('Pinned') }}
                </h2>
                <div
                    v-if="collapsed"
                    class="mx-2 mb-3 border-t border-(--ds-divider)"
                    aria-hidden="true"
                />
                <ul class="flex flex-col gap-px">
                    <li
                        v-for="workspace in pinned"
                        :key="workspace.id"
                        class="group/pin relative"
                    >
                        <a
                            :href="workspace.url"
                            class="hover:bg-(--ds-hover)"
                            :class="[
                                rowClass,
                                collapsed
                                    ? 'justify-center px-0'
                                    : 'pr-8 pl-2.5',
                                workspace.id === page.props.workspace?.id
                                    ? 'text-(--ds-text)'
                                    : 'text-(--ds-text-secondary) hover:text-(--ds-text)',
                                focusRing,
                            ]"
                            :aria-current="
                                workspace.id === page.props.workspace?.id
                                    ? 'true'
                                    : undefined
                            "
                            :aria-label="collapsed ? workspace.name : undefined"
                            :title="collapsed ? workspace.name : undefined"
                            @click.prevent="openPinned(workspace)"
                        >
                            <span
                                class="flex size-4 shrink-0 items-center justify-center overflow-hidden rounded-[4px] text-[9px]/3 font-semibold"
                                :class="workspaceTileColor(workspace.id)"
                                aria-hidden="true"
                            >
                                <img
                                    v-if="workspace.logoUrl"
                                    :src="workspace.logoUrl"
                                    alt=""
                                    class="size-full object-cover"
                                />
                                <template v-else>{{
                                    workspaceInitial(workspace.name)
                                }}</template>
                            </span>
                            <span v-if="!collapsed" class="truncate">
                                {{ workspace.name }}
                            </span>
                        </a>
                        <button
                            v-if="!collapsed"
                            type="button"
                            class="absolute top-1/2 right-1 flex size-6 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-(--ds-text-faint) opacity-0 group-hover/pin:opacity-100 hover:bg-(--ds-active) hover:text-(--ds-text) focus-visible:opacity-100"
                            :class="focusRing"
                            :aria-label="
                                $t('Unpin :name', {
                                    name: String(workspace.name),
                                })
                            "
                            :title="$t('Unpin')"
                            @click="unpin(workspace)"
                        >
                            <PinOff class="size-3.5" :stroke-width="1.75" />
                        </button>
                    </li>
                </ul>
            </section>
        </nav>

        <div
            class="flex shrink-0 items-center gap-1"
            :class="collapsed ? 'flex-col justify-center' : 'pt-1'"
        >
            <WorkspaceMenu
                v-if="!collapsed"
                variant="block"
                class="flex min-w-0 flex-1"
            />
            <AccountMenu
                display="avatar"
                :side="collapsed ? 'right' : 'top'"
                :align="collapsed ? 'end' : 'start'"
            />
        </div>
    </div>
</template>
