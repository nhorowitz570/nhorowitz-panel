<script setup lang="ts">
import SubscriptionBanner from '@/components/workspace/SubscriptionBanner.vue';
import { useSubscriptionNotice } from '@/composables/useSubscriptionNotice';
import { usePage } from '@inertiajs/vue3';
import {
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import NotchToaster from '@/components/workspace/NotchToaster.vue';
import type AssistantPanelComponent from '@/components/workspace/AssistantPanel.vue';
import WorkspacePageSkeleton from '@/components/workspace/WorkspacePageSkeleton.vue';
import WorkspaceSidebar from '@/components/workspace/WorkspaceSidebar.vue';
import WorkspaceNavBar from '@/components/workspace/WorkspaceNavBar.vue';
import { useAssistant } from '@/composables/useAssistant';
import { useWorkspaceSwitch } from '@/composables/useWorkspaceSwitch';
import { useIsDark } from '@/composables/useAppearance';
import { useNavLayout } from '@/composables/useNavLayout';
import { usePanelUpdates } from '@/composables/usePanelUpdates';

const AssistantPanel = defineAsyncComponent(
    () => import('@/components/workspace/AssistantPanel.vue'),
);
const CommandPalette = defineAsyncComponent(
    () => import('@/components/workspace/CommandPalette.vue'),
);
const WhatsNewDialog = defineAsyncComponent(
    () => import('@/components/workspace/WhatsNewDialog.vue'),
);

const subscriptionNotice = useSubscriptionNotice();
usePanelUpdates();

const page = usePage();
const isDark = useIsDark();
const { switchingTo } = useWorkspaceSwitch();
const mobileOpen = ref(false);
const searching = ref(false);

const { layout } = useNavLayout();
const COLLAPSED_KEY = 'workspace-sidebar-collapsed';
const collapsed = ref(false);

function toggleCollapsed(): void {
    collapsed.value = !collapsed.value;

    try {
        window.localStorage.setItem(COLLAPSED_KEY, collapsed.value ? '1' : '0');
    } catch {}
}
const {
    open: assistantOpen,
    expanded: assistantExpanded,
    toggle: toggleAssistant,
    activity: assistantActivity,
} = useAssistant();

const isDesktop = ref(false);
let desktopQuery: MediaQueryList | undefined;
function syncDesktop(): void {
    isDesktop.value = desktopQuery?.matches ?? false;
}
const assistantPanel =
    useTemplateRef<InstanceType<typeof AssistantPanelComponent>>(
        'assistantPanel',
    );
const assistantSheet =
    useTemplateRef<InstanceType<typeof AssistantPanelComponent>>(
        'assistantSheet',
    );

function onShortcut(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'j') {
        event.preventDefault();
        toggleAssistant();
    }
}

watch(assistantOpen, (open) => {
    if (open) {
        mobileOpen.value = false;
        setTimeout(() => {
            assistantPanel.value?.focus();
            assistantSheet.value?.focus();
        }, 220);
    }
});

onMounted(() => {
    window.addEventListener('keydown', onShortcut);
    desktopQuery = window.matchMedia('(min-width: 64rem)');
    syncDesktop();
    desktopQuery.addEventListener('change', syncDesktop);

    try {
        collapsed.value = window.localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {}
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onShortcut);
    desktopQuery?.removeEventListener('change', syncDesktop);
});

function openSearch(): void {
    mobileOpen.value = false;
    searching.value = true;
}

watch(
    () => page.url,
    () => {
        mobileOpen.value = false;
    },
);
</script>

<template>
    <div
        class="ds-root flex h-dvh flex-col overflow-hidden"
        :class="
            subscriptionNotice?.tone === 'urgent'
                ? 'bg-(--ds-amber-bg)'
                : 'bg-(--ds-panel)'
        "
    >
        <SubscriptionBanner />
        <div
            class="relative flex min-h-0 flex-1 overflow-hidden bg-(--ds-panel) font-ui text-(--ds-text) antialiased"
        >
            <a
                href="#main"
                class="sr-only z-50 rounded-[6px] bg-(--ds-hover) px-3 py-1.5 text-xs focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:outline-2 focus:outline-(--ds-ring)"
            >
                {{ ' ' + $t('Skip to content') + ' ' }}
            </a>

            <div
                class="flex min-h-0 min-w-0 flex-1 overflow-hidden bg-(--ds-frame) lg:bg-(--ds-panel) lg:p-2"
            >
                <aside
                    v-if="layout === 'sidebar'"
                    class="hidden shrink-0 transition-[width] duration-200 ease-out motion-reduce:transition-none lg:block"
                    :class="collapsed ? 'w-14' : 'w-[228px]'"
                >
                    <WorkspaceSidebar
                        :collapsed="collapsed"
                        collapsible
                        @toggle="toggleCollapsed"
                        @search="openSearch"
                    />
                </aside>

                <div
                    class="relative isolate flex min-h-0 min-w-0 flex-1 flex-col bg-(--ds-frame) lg:overflow-hidden lg:rounded-[14px] lg:shadow-[0_0_0_1px_var(--ds-border)] lg:dark:shadow-none"
                    :class="{ 'lg:ml-[3px]': layout === 'sidebar' }"
                >
                    <WorkspaceNavBar
                        :sidebar="layout === 'sidebar'"
                        @search="openSearch"
                        @menu="mobileOpen = true"
                    />

                    <main
                        id="main"
                        class="relative isolate flex min-h-0 min-w-0 grow flex-col overflow-hidden"
                    >
                        <div
                            class="assistant-page-glow pointer-events-none absolute inset-0 z-20 rounded-[inherit]"
                            :class="{
                                'is-on':
                                    assistantActivity === 'working' ||
                                    assistantActivity === 'waiting',
                            }"
                            :style="{
                                '--assistant-page-glow':
                                    assistantActivity === 'waiting'
                                        ? '245 158 11'
                                        : '0 229 214',
                            }"
                            aria-hidden="true"
                        />
                        <div
                            class="min-h-0 grow overflow-y-auto overscroll-contain"
                        >
                            <WorkspacePageSkeleton v-if="switchingTo" />
                            <slot v-else />
                        </div>
                    </main>
                </div>
            </div>

            <div
                v-if="isDesktop"
                id="assistant-panel"
                class="hidden shrink-0 overflow-hidden transition-[width,opacity] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none lg:block"
                :class="
                    assistantOpen
                        ? 'opacity-100'
                        : 'pointer-events-none opacity-0'
                "
                :style="{
                    width: assistantOpen
                        ? assistantExpanded
                            ? '50%'
                            : '400px'
                        : '0px',
                }"
                :inert="!assistantOpen"
                @keydown.esc="assistantOpen = false"
            >
                <div
                    class="h-full overflow-hidden border-l border-(--ds-border) bg-(--ds-frame)"
                    :class="
                        assistantExpanded ? 'w-full min-w-[400px]' : 'w-[400px]'
                    "
                >
                    <AssistantPanel
                        ref="assistantPanel"
                        @close="assistantOpen = false"
                    />
                </div>
            </div>

            <Sheet v-model:open="mobileOpen">
                <SheetContent
                    side="left"
                    class="w-64 gap-0 border-(--ds-border) bg-(--ds-frame) p-0 font-ui text-(--ds-text)"
                >
                    <SheetTitle class="sr-only">{{
                        $t('Navigation')
                    }}</SheetTitle>
                    <SheetDescription class="sr-only">
                        {{
                            ' ' +
                            $t('Workspace navigation and account menu') +
                            ' '
                        }}
                    </SheetDescription>
                    <WorkspaceSidebar
                        @search="openSearch"
                        @assistant="mobileOpen = false"
                        @whats-new="mobileOpen = false"
                    />
                </SheetContent>
            </Sheet>

            <Sheet
                :open="assistantOpen && !isDesktop"
                @update:open="(value) => (assistantOpen = value)"
            >
                <SheetContent
                    side="right"
                    data-assistant-sheet
                    class="w-full gap-0 border-(--ds-border) bg-(--ds-panel) p-0 sm:max-w-[420px] [&>button]:hidden"
                >
                    <SheetTitle class="sr-only">{{ $t('Ask AI') }}</SheetTitle>
                    <SheetDescription class="sr-only">
                        {{
                            ' ' +
                            $t(
                                'Ask questions and get things done in your workspace',
                            ) +
                            ' '
                        }}
                    </SheetDescription>
                    <AssistantPanel
                        ref="assistantSheet"
                        @close="assistantOpen = false"
                    />
                </SheetContent>
            </Sheet>

            <CommandPalette v-model:open="searching" />
            <WhatsNewDialog />
            <NotchToaster />
        </div>
    </div>
</template>
