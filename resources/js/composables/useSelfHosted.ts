import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { Workspace } from '@/types';

export const LICENSE_SETTINGS_PATH = '/settings/license';

export function useSelfHosted(): ComputedRef<boolean> {
    const page = usePage<{ workspace?: Workspace }>();

    return computed(() => page.props.workspace?.billing?.selfHosted === true);
}
