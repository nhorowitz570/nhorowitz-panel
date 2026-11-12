<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Search } from '@lucide/vue';
import {
    SolarLayoutDashboard as LayoutDashboard,
    SolarNotebookPen as NotebookPen,
    SolarPulse as Activity,
    SolarRocket as Rocket,
    SolarUsers as Users,
} from '@/lib/solarIcons';
import type { Component } from 'vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import ExtensionSlot from '@/components/ExtensionSlot.vue';
import NotchToaster from '@/components/workspace/NotchToaster.vue';
import { useIsDark } from '@/composables/useAppearance';
import { adminIcon } from '@/lib/adminIcons';
import { panelShadow } from '@/lib/workspacePanel';

type AdminNavItem = {
    key: string;
    label: string;
    icon: string;
    href: string;
    components: string[];
    badge: string | null;
    external: boolean;
};

const page = usePage<{
    auth: { user: { email: string; name: string } | null };
    staff: { admin: boolean; sales: boolean };
    adminNav: { key: string; items: AdminNavItem[] }[] | null;
}>();
const isDark = useIsDark();

const slotParts = import.meta.glob<Component>(
    '../../../vendor/serveroshq/*/resources/js/slots/layouts/AdminLayout/**/*.vue',
    { eager: true, import: 'default' },
);

const groupLabels: Record<string, string | null> = {
    home: null,
    customers: 'Customers',
    growth: 'Growth',
    content: 'Content',
    platform: 'Platform',
};

function isActive(item: AdminNavItem): boolean {
    return item.components.some((pattern) =>
        pattern.endsWith('*')
            ? page.component.startsWith(pattern.slice(0, -1))
            : page.component === pattern,
    );
}

function toLink(item: AdminNavItem) {
    const badge = item.badge ? Number(page.props[item.badge] ?? 0) : 0;

    return {
        label: item.label,
        icon: adminIcon(item.icon),
        href: item.href,
        active: isActive(item),
        external: item.external,
        badge,
    };
}

const allGroups = computed(() =>
    (page.props.adminNav ?? [])
        .filter((group) => group.key !== 'tools')
        .map((group) => ({
            key: group.key,
            label: groupLabels[group.key] ?? null,
            items: group.items.map(toLink),
        })),
);

const tools = computed(() =>
    (page.props.adminNav ?? [])
        .filter((group) => group.key === 'tools')
        .flatMap((group) => group.items.map(toLink)),
);

const groupIcons: Record<string, Component> = {
    home: LayoutDashboard,
    customers: Users,
    growth: Rocket,
    content: NotebookPen,
    platform: Activity,
};

const groups = computed(() =>
    page.props.staff?.admin === false
        ? [
              {
                  key: 'sales',
                  label: null,
                  items: allGroups.value.flatMap((group) => group.items),
              },
          ]
        : allGroups.value,
);

const chosen = ref<string | null>(null);
watch(
    () => page.component,
    () => {
        chosen.value = null;
        query.value = '';
    },
);
const selected = computed(
    () =>
        groups.value.find((group) => group.key === chosen.value) ??
        groups.value.find((group) => group.items.some((i) => i.active)) ??
        groups.value[0],
);

const query = ref('');
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');
const results = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return [];
    return groups.value.flatMap((group) =>
        group.items.filter((item) =>
            `${item.label} ${group.label ?? ''}`.toLowerCase().includes(q),
        ),
    );
});
const shown = computed(() =>
    query.value.trim() ? results.value : (selected.value?.items ?? []),
);

function openFirst(): void {
    const first = results.value[0];
    if (!first) return;
    if ('external' in first && first.external) {
        window.location.href = first.href;
    } else {
        router.visit(first.href);
    }
}

function onKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const typing =
        target?.closest('input, textarea, select, [contenteditable]') !== null;
    if (
        (event.key === 'k' && (event.metaKey || event.ctrlKey)) ||
        (event.key === '/' && !typing)
    ) {
        event.preventDefault();
        searchInput.value?.focus();
        searchInput.value?.select();
    }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div
        class="ds-root flex h-dvh flex-col overflow-hidden bg-(--ds-frame) font-ui text-(--ds-text) antialiased lg:flex-row"
    >
        <aside class="shrink-0 lg:w-52">
            <div class="flex h-full flex-col px-2.5 py-3">
                <div class="mb-2 flex h-9 items-center gap-2.25 px-2.5">
                    <img
                        src="/images/landing/serveros-logo.svg"
                        alt=""
                        class="size-4 shrink-0 object-contain invert dark:invert-0"
                    />
                    <span class="text-[14px]/4 font-semibold tracking-[-0.01em]"
                        >ServerOS</span
                    >
                    <span
                        class="rounded-[4px] bg-(--ds-hover) px-1.5 text-[10px]/4 font-semibold tracking-wide text-(--ds-text-muted) uppercase"
                        >Admin</span
                    >
                </div>

                <nav
                    class="flex gap-px overflow-x-auto lg:hidden"
                    aria-label="Admin"
                >
                    <component
                        :is="'external' in item && item.external ? 'a' : Link"
                        v-for="item in groups.flatMap((g) => g.items)"
                        :key="item.href"
                        :href="item.href"
                        class="sidebar-item flex h-7.5 shrink-0 items-center gap-2 rounded-[7px] px-2.5 text-[13px]/4 font-medium transition-colors duration-200"
                        :class="[
                            item.active
                                ? 'bg-(--ds-hover) text-(--ds-text)'
                                : 'text-(--ds-text-secondary) hover:bg-(--ds-hover) hover:text-(--ds-text)',
                            focusRing,
                        ]"
                        :aria-current="item.active ? 'page' : undefined"
                    >
                        <component
                            :is="item.icon"
                            class="size-[15px] shrink-0"
                            :stroke-width="1.6"
                        />
                        <span class="truncate">{{ item.label }}</span>
                        <span
                            v-if="'badge' in item && item.badge"
                            class="ml-auto rounded-full bg-(--ds-blue) px-1.5 text-[10px]/4 font-semibold text-white tabular-nums"
                            :aria-label="`${item.badge} unread`"
                            >{{ item.badge }}</span
                        >
                    </component>
                </nav>

                <div class="hidden flex-col lg:mt-2 lg:flex">
                    <label
                        class="flex h-8 items-center gap-2 rounded-[8px] border border-(--ds-border) bg-(--ds-panel) px-2.5 text-(--ds-text-muted) transition-colors focus-within:border-(--ds-border-focus) hover:border-(--ds-border-hover)"
                    >
                        <Search
                            class="size-3.5 shrink-0"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        <input
                            ref="searchInput"
                            v-model="query"
                            type="search"
                            placeholder="Search"
                            aria-label="Search admin pages"
                            class="min-w-0 flex-1 bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                            @keydown.enter.prevent="openFirst"
                            @keydown.esc="query = ''"
                        />
                        <kbd
                            v-if="!query"
                            class="rounded-[4px] border border-(--ds-border) px-1 font-ui text-[10.5px]/4 text-(--ds-text-faint)"
                            aria-hidden="true"
                            >/</kbd
                        >
                    </label>

                    <nav
                        v-if="groups.length > 1 && !query.trim()"
                        aria-label="Category"
                        class="mt-3 rounded-[10px] border border-(--ds-border) bg-(--ds-hover)/70 p-0.5"
                    >
                        <p
                            class="px-2.5 pt-1.5 pb-1 text-[11.5px]/4 text-(--ds-text-faint)"
                        >
                            Category
                        </p>
                        <ul class="flex flex-col gap-px">
                            <li v-for="group in groups" :key="group.key">
                                <button
                                    type="button"
                                    class="flex h-8 w-full cursor-pointer items-center gap-2.5 rounded-[8px] px-2.5 text-left text-[13px]/4 font-medium transition-[color,background-color,box-shadow] duration-200"
                                    :class="[
                                        group === selected
                                            ? 'bg-(--ds-panel) text-(--ds-text) shadow-[0_0_0_1px_var(--ds-border),0_1px_2px_rgb(0_0_0/0.06)] dark:bg-(--ds-active)'
                                            : 'text-(--ds-text-muted) hover:text-(--ds-text)',
                                        focusRing,
                                    ]"
                                    :aria-pressed="group === selected"
                                    @click="chosen = group.key"
                                >
                                    <component
                                        :is="groupIcons[group.key]"
                                        class="size-[15px] shrink-0 transition-colors duration-200"
                                        :class="
                                            group === selected
                                                ? 'text-(--ds-blue)'
                                                : 'text-(--ds-text-faint)'
                                        "
                                        :stroke-width="1.6"
                                    />
                                    {{ group.label ?? 'Home' }}
                                    <span
                                        v-if="
                                            group !== selected &&
                                            group.items.some(
                                                (i) => 'badge' in i && i.badge,
                                            )
                                        "
                                        class="ml-auto size-1.5 rounded-full bg-(--ds-blue)"
                                        aria-label="unread"
                                    />
                                </button>
                            </li>
                        </ul>
                    </nav>

                    <nav
                        :aria-label="
                            query.trim()
                                ? 'Search results'
                                : (selected?.label ?? 'Home')
                        "
                        class="mt-3 flex flex-col gap-px"
                    >
                        <component
                            :is="
                                'external' in item && item.external ? 'a' : Link
                            "
                            v-for="item in shown"
                            :key="item.href"
                            :href="item.href"
                            class="sidebar-item flex h-7.5 shrink-0 items-center gap-2 rounded-[7px] px-2.5 text-[13px]/4 font-medium transition-colors duration-200"
                            :class="[
                                item.active
                                    ? 'bg-(--ds-hover) text-(--ds-text)'
                                    : 'text-(--ds-text-secondary) hover:bg-(--ds-hover) hover:text-(--ds-text)',
                                focusRing,
                            ]"
                            :aria-current="item.active ? 'page' : undefined"
                        >
                            <component
                                :is="item.icon"
                                class="size-[15px] shrink-0"
                                :stroke-width="1.6"
                            />
                            <span class="truncate">{{ item.label }}</span>
                            <span
                                v-if="'badge' in item && item.badge"
                                class="ml-auto rounded-full bg-(--ds-blue) px-1.5 text-[10px]/4 font-semibold text-white tabular-nums"
                                :aria-label="`${item.badge} unread`"
                                >{{ item.badge }}</span
                            >
                        </component>
                        <p
                            v-if="query.trim() && !results.length"
                            class="px-2.5 py-1.5 text-[12.5px]/4 text-(--ds-text-faint)"
                        >
                            Nothing matches “{{ query.trim() }}”.
                        </p>
                    </nav>
                </div>

                <div class="mt-auto hidden flex-col gap-3 px-2.5 pt-4 lg:flex">
                    <div
                        v-if="tools.length"
                        class="-mx-1.5 flex items-center gap-0.5"
                    >
                        <a
                            v-for="tool in tools"
                            :key="tool.href"
                            :href="tool.href"
                            target="_blank"
                            rel="noopener"
                            :title="tool.label"
                            :aria-label="`${tool.label}, opens in a new tab`"
                            class="flex size-7 items-center justify-center rounded-[7px] text-(--ds-text-faint) transition-colors hover:bg-(--ds-hover) hover:text-(--ds-text)"
                            :class="focusRing"
                        >
                            <component
                                :is="tool.icon"
                                class="size-[15px]"
                                :stroke-width="1.6"
                            />
                        </a>
                    </div>
                    <a
                        href="/dashboard"
                        class="flex items-center gap-1.5 rounded-md text-[12px]/4 text-(--ds-text-muted) hover:text-(--ds-text)"
                        :class="focusRing"
                    >
                        <ArrowLeft class="size-3.5" :stroke-width="1.75" />
                        Your workspace
                    </a>
                    <p
                        v-if="page.props.auth.user"
                        class="truncate text-[11px]/4 text-(--ds-text-faint)"
                    >
                        {{ page.props.auth.user.email }}
                    </p>
                </div>
            </div>
        </aside>

        <div
            class="relative flex min-h-0 min-w-0 grow px-2 pb-2 lg:py-2 lg:pr-2 lg:pl-0"
        >
            <main
                id="main"
                class="min-h-0 min-w-0 grow overflow-y-auto overscroll-contain rounded-[10px] bg-(--ds-panel)"
                :style="{ boxShadow: panelShadow }"
            >
                <slot />
                <ExtensionSlot
                    name="layouts/AdminLayout/main-end"
                    :parts="slotParts"
                />
            </main>
            <ExtensionSlot
                name="layouts/AdminLayout/after-main"
                :parts="slotParts"
            />
        </div>

        <NotchToaster />
    </div>
</template>
