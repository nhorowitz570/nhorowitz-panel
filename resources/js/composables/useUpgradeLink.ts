import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

export type UpgradeLink = { href: string; label: string };

export function useUpgradeLink(): ComputedRef<UpgradeLink | null> {
    const page = usePage();

    return computed(() => page.props.upgrade ?? null);
}
