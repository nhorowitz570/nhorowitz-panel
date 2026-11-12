<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { useSettingsNav } from '@/components/workspace/settingsNav';
import type { Result } from '@/components/workspace/settingsNav';
import { useNavLayout } from '@/composables/useNavLayout';
import type { Workspace } from '@/types';

const page = usePage<{ workspace: Workspace }>();
const groups = useSettingsNav();
const { layout } = useNavLayout();
const inSidebar = computed(() => layout.value === 'sidebar');

const query = ref('');
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');
const searching = computed(() => query.value.trim() !== '');

const results = computed<Result[]>(() => {
    const words = query.value.trim().toLowerCase().split(/\s+/);
    const matches = (...text: (string | undefined)[]): boolean => {
        const haystack = text.join(' ').toLowerCase();

        return words.every((word) => haystack.includes(word));
    };

    const pages: Result[] = [];
    const sections: Result[] = [];

    for (const group of groups.value) {
        for (const item of group.items) {
            if (matches(item.label, group.label, item.keywords)) {
                pages.push({
                    key: item.href,
                    label: item.label,
                    page: null,
                    icon: item.icon,
                    href: item.href,
                });
            }

            for (const section of item.sections) {
                if (matches(section.label, item.label, section.keywords)) {
                    sections.push({
                        key: `${item.href}#${section.anchor}`,
                        label: section.label,
                        page: item.label,
                        icon: item.icon,
                        href: `${item.href}#${section.anchor}`,
                    });
                }
            }
        }
    }

    return [...pages, ...sections];
});

const selected = ref(0);

watch(query, () => (selected.value = 0));

function onSearchKeydown(event: KeyboardEvent): void {
    const count = results.value.length;

    if (event.key === 'Escape') {
        query.value = '';
    } else if (event.key === 'ArrowDown' && count) {
        event.preventDefault();
        selected.value = (selected.value + 1) % count;
    } else if (event.key === 'ArrowUp' && count) {
        event.preventDefault();
        selected.value = (selected.value - 1 + count) % count;
    } else if (event.key === 'Enter' && count) {
        event.preventDefault();
        router.visit(results.value[selected.value].href);
    }
}

function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const typing =
        target?.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '');

    if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
        event.preventDefault();
        searchInput.value?.focus();
    }
}

function revealHash(): void {
    const id = window.location.hash.slice(1);
    const target = id ? document.getElementById(id) : null;

    if (!target) {
        return;
    }

    target.scrollIntoView({ block: 'start', behavior: 'smooth' });
    target.classList.remove('settings-found');
    void target.offsetWidth;
    target.classList.add('settings-found');
}

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

function setLinkEl(key: string, link: unknown): void {
    setItemEl(key, (link as { $el?: unknown } | null)?.$el);
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
    if (searching.value) {
        return results.value[selected.value]?.key;
    }

    return (
        pending.value ??
        groups.value.flatMap((g) => g.items).find((item) => item.current)?.href
    );
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
const stops: (() => void)[] = [];

onMounted(async () => {
    window.addEventListener('keydown', onKeydown);
    await nextTick();
    measure();
    requestAnimationFrame(() => (activeReady.value = true));
    revealHash();

    if (track.value && 'ResizeObserver' in window) {
        observer = new ResizeObserver(measure);
        observer.observe(track.value);
    }

    stops.push(
        router.on('finish', () => (pending.value = null)),
        router.on('navigate', () => {
            query.value = '';
            nextTick(revealHash);
        }),
    );
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    observer?.disconnect();
    stops.forEach((stop) => stop());
});

watch(
    () => [searching.value, results.value.length],
    async () => {
        hovered.value = null;
        await nextTick();
        measure();
    },
);

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <WorkspacePage
        :title="$t('Settings')"
        :description="
            $t(':name, its machines and your own account.', {
                name: String(page.props.workspace.name),
            })
        "
    >
        <div
            class="flex flex-col gap-6"
            :class="{ 'xl:flex-row xl:items-start xl:gap-10': !inSidebar }"
        >
            <div
                class="flex shrink-0 flex-col gap-3"
                :class="{ 'xl:sticky xl:top-6 xl:w-56 xl:gap-4': !inSidebar }"
            >
                <label
                    class="ds-raised-button w-full cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring) sm:max-w-80"
                    :class="{ 'xl:max-w-none': !inSidebar }"
                >
                    <Search
                        class="size-3.5 shrink-0 text-(--ds-text-muted)"
                        :stroke-width="1.6"
                    />
                    <span class="sr-only">{{ $t('Search settings') }}</span>
                    <input
                        ref="searchInput"
                        v-model="query"
                        type="search"
                        :placeholder="$t('Search settings')"
                        role="combobox"
                        aria-controls="settings-nav"
                        :aria-expanded="searching"
                        :aria-activedescendant="
                            searching && results.length
                                ? `settings-result-${selected}`
                                : undefined
                        "
                        class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                        @keydown="onSearchKeydown"
                    />
                    <kbd
                        class="rounded-[5px] border border-(--ds-border-hover) px-1.25 py-px font-ui text-[11px]/3.5 text-(--ds-text-muted)"
                        aria-hidden="true"
                    >
                        /
                    </kbd>
                </label>

                <nav
                    v-show="!inSidebar || searching"
                    id="settings-nav"
                    :aria-label="$t('Settings')"
                    class="-mx-4 overflow-x-auto px-4 sm:-mx-8 sm:px-8 xl:mx-0 xl:overflow-visible xl:px-0"
                >
                    <div ref="track" class="relative" @mouseleave="leave">
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

                        <template v-if="searching">
                            <ul
                                v-if="results.length"
                                role="listbox"
                                :aria-label="$t('Search settings')"
                                class="flex flex-col gap-0.5"
                            >
                                <li
                                    v-for="(result, index) in results"
                                    :id="`settings-result-${index}`"
                                    :key="result.key"
                                    :ref="(el) => setItemEl(result.key, el)"
                                    role="option"
                                    :aria-selected="index === selected"
                                >
                                    <Link
                                        :href="result.href"
                                        class="sidebar-item flex h-8 items-center gap-2 rounded-[7px] px-2.5 text-[13px]/4 font-medium"
                                        :class="[
                                            index === selected
                                                ? 'text-(--ds-text)'
                                                : 'text-(--ds-text-secondary) hover:text-(--ds-text)',
                                            focusRing,
                                        ]"
                                        tabindex="-1"
                                        @mouseenter="enter(result.key)"
                                    >
                                        <component
                                            :is="result.icon"
                                            class="size-4 shrink-0"
                                        />
                                        <span class="truncate">
                                            {{ result.label }}
                                        </span>
                                        <span
                                            v-if="result.page"
                                            class="ml-auto shrink-0 pl-2 text-[12px]/4 font-normal text-(--ds-text-faint)"
                                        >
                                            {{ result.page }}
                                        </span>
                                    </Link>
                                </li>
                            </ul>
                            <p
                                v-else
                                class="px-2.5 py-1.5 text-[13px]/5 text-(--ds-text-muted)"
                            >
                                {{ $t('Nothing in settings matches that.') }}
                            </p>
                        </template>

                        <div v-else class="flex gap-1 xl:flex-col xl:gap-5">
                            <div
                                v-for="group in groups"
                                :key="group.label"
                                class="contents xl:flex xl:flex-col xl:gap-0.5"
                            >
                                <h2
                                    class="hidden px-2.5 pb-1 text-[11px]/4 font-medium text-(--ds-text-faint) xl:block"
                                >
                                    {{ group.label }}
                                </h2>
                                <Link
                                    v-for="link in group.items"
                                    :key="link.href"
                                    :ref="(el) => setLinkEl(link.href, el)"
                                    :href="link.href"
                                    class="sidebar-item flex h-8 shrink-0 items-center gap-2 rounded-[7px] px-2.5 text-[13px]/4 font-medium whitespace-nowrap"
                                    :class="[
                                        link.href === currentKey
                                            ? 'text-(--ds-text)'
                                            : 'text-(--ds-text-secondary) hover:text-(--ds-text)',
                                        focusRing,
                                    ]"
                                    :aria-current="
                                        link.current ? 'page' : undefined
                                    "
                                    @mouseenter="enter(link.href)"
                                    @click="pending = link.href"
                                >
                                    <component
                                        :is="link.icon"
                                        class="size-4 shrink-0"
                                    />
                                    {{ link.label }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </nav>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-5 xl:max-w-5xl">
                <slot />
            </div>
        </div>
    </WorkspacePage>
</template>
