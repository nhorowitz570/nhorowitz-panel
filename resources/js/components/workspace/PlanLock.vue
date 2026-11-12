<script setup lang="ts">
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { SolarLock as Lock } from '@/lib/solarIcons';
import { ref } from 'vue';
import AppButton from '@/components/workspace/AppButton.vue';
import UpgradeDialog from '@/components/workspace/UpgradeDialog.vue';
import { planNames } from '@/lib/planLocks';
import type { LockedPlan } from '@/lib/planLocks';

const upgrade = useUpgradeLink();

const {
    locked,
    feature,
    plan = 'pro',
    description = '',
} = defineProps<{
    locked: boolean;
    feature: string;
    plan?: LockedPlan;
    description?: string;
}>();

const upgrading = ref(false);
</script>

<template>
    <slot v-if="!locked" />
    <div v-else class="relative isolate">
        <div
            class="pointer-events-none blur-[1.5px] select-none"
            aria-hidden="true"
            inert
        >
            <slot />
        </div>
        <div class="absolute inset-0 z-10 flex items-center justify-center p-4">
            <div
                class="ds-tile flex w-full max-w-[360px] flex-col gap-3 p-5 shadow-2xl"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="flex items-center gap-2 text-sm/5 font-semibold">
                        <Lock
                            class="size-3.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                        {{
                            $t(':feature come with :value', {
                                feature,
                                value: planNames[plan],
                            })
                        }}
                    </h2>
                    <p
                        v-if="description"
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{ description }}
                    </p>
                </div>
                <div class="flex justify-end gap-2">
                    <AppButton
                        v-if="upgrade"
                        variant="quiet"
                        :href="upgrade.href"
                    >
                        {{ ' ' + $t('Compare plans') + ' ' }}
                    </AppButton>
                    <AppButton variant="primary" @click="upgrading = true">
                        {{
                            ' ' +
                            $t('Upgrade to :value', { value: planNames[plan] })
                        }}
                    </AppButton>
                </div>
            </div>
        </div>
        <UpgradeDialog
            v-model:open="upgrading"
            :feature="feature"
            :plan="plan"
        />
    </div>
</template>
