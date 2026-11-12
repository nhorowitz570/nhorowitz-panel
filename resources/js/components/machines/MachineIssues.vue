<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import AskAiButton from '@/components/workspace/AskAiButton.vue';
import type { HealthIssue } from '@/lib/healthIssues';

const props = defineProps<{ issues: HealthIssue[] }>();
const page = usePage<{ workspaceAccess?: { permissions: string[] } }>();

const shown = computed(() =>
    props.issues.filter((issue) => issue.ask && issue.severity !== 'notice'),
);

const dot: Record<HealthIssue['severity'], string> = {
    critical: 'bg-(--ds-red)',
    warning: 'bg-(--ds-amber)',
    notice: 'bg-(--ds-text-faint)',
};

const canDismiss = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const dismissing = ref<string[]>([]);

function dismiss(id: string): void {
    dismissing.value = [id];
    router.post(
        '/issues/dismiss',
        { ids: [id] },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['issues'],
            onFinish: () => (dismissing.value = []),
        },
    );
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <section
        v-if="shown.length"
        class="ds-tile divide-y divide-(--ds-divider)"
        :aria-label="$t('Needs attention')"
    >
        <div
            v-for="issue in shown"
            :key="issue.id"
            class="flex items-center gap-3 px-4 py-3 max-sm:flex-col max-sm:items-start"
        >
            <span
                class="size-2 shrink-0 rounded-full max-sm:hidden"
                :class="dot[issue.severity]"
                :aria-label="issue.severity"
            />
            <div class="min-w-0 grow">
                <p class="text-[13px]/5 font-medium text-(--ds-text)">
                    {{ issue.title }}
                </p>
                <p
                    class="line-clamp-1 text-[13px]/5 text-(--ds-text-secondary)"
                    :title="issue.detail"
                >
                    {{ issue.detail }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
                <AskAiButton :prompt="issue.ask!" />
                <Link
                    :href="issue.href"
                    class="ds-raised-button shrink-0 text-[13px]!"
                    :class="focusRing"
                >
                    {{ issue.action }}
                </Link>
                <button
                    v-if="canDismiss"
                    type="button"
                    class="ds-raised-button flex size-8 shrink-0 cursor-pointer items-center justify-center p-0! disabled:opacity-50"
                    :class="focusRing"
                    :disabled="dismissing.includes(issue.id)"
                    :title="$t('Mark as read')"
                    :aria-label="
                        $t('Mark as read: :title', {
                            title: String(issue.title),
                        })
                    "
                    @click="dismiss(issue.id)"
                >
                    <Check class="size-3.5" :stroke-width="1.9" />
                </button>
            </div>
        </div>
    </section>
</template>
