<script setup lang="ts">
import { computed } from 'vue';
import { environmentLabels } from '@/components/machines/machines';
import type { Environment } from '@/components/machines/machines';

type Props = {
    environment: Environment;
    themed?: boolean;
};

const props = defineProps<Props>();

const badgeShadowTail =
    '#00000059 0px -1px 0px inset, #00000080 0px 1px 2px, #00000099 0px 2px 6px -2px';

const variants: Record<
    Environment,
    {
        badgeClass: string;
        dotClass: string;
        textClass: string;
        style: Record<string, string>;
    }
> = {
    production: {
        badgeClass: 'border-[#2F4C80] bg-[#1B2A45]',
        dotClass: 'bg-[#60A5FA] shadow-[0_0_0_2px_#60A5FA33]',
        textClass: 'text-[#93BBFD]',
        style: {
            boxShadow: `#93BBFD38 0px 1px 0px inset, ${badgeShadowTail}`,
            backgroundImage:
                'linear-gradient(in oklab 180deg, oklab(33.3% -0.009 -0.070) 0%, oklab(26.7% -0.007 -0.054) 100%)',
        },
    },
    staging: {
        badgeClass: 'border-[#6B5220] bg-[#3A2C12]',
        dotClass: 'bg-[#FBBF24] shadow-[0_0_0_2px_#FBBF2433]',
        textClass: 'text-[#FCD34D]',
        style: {
            boxShadow: `#FCD34D33 0px 1px 0px inset, ${badgeShadowTail}`,
            backgroundImage:
                'linear-gradient(in oklab 180deg, oklab(34.5% 0.010 0.048) 0%, oklab(28.1% 0.005 0.041) 100%)',
        },
    },
    development: {
        badgeClass: 'border-[#3F3F43] bg-[#262628]',
        dotClass: 'bg-[#A1A1AA] shadow-[0_0_0_2px_#A1A1AA2E]',
        textClass: 'text-[#C4C4CC]',
        style: {
            boxShadow: `#FFFFFF1A 0px 1px 0px inset, ${badgeShadowTail}`,
            backgroundImage:
                'linear-gradient(in oklab 180deg, oklab(30.2% 0.001 -0.005) 0%, oklab(25.3% 0.001 -0.004) 100%)',
        },
    },
};

// In the panel: a coloured dot and a plain label. The colour carries the
// meaning; a border, fill and glow on top of it was saying it three times.
const themedDots: Record<Environment, string> = {
    production: 'bg-[#2563EB] dark:bg-[#60A5FA]',
    staging: 'bg-[#D97706] dark:bg-[#FBBF24]',
    development: 'bg-[#A1A1AA] dark:bg-[#71717A]',
};

const variant = computed(() => {
    if (!props.themed) {
        return variants[props.environment];
    }

    return {
        badgeClass: '',
        dotClass: themedDots[props.environment],
        textClass: 'text-(--ds-text-secondary)',
        style: {},
    };
});
</script>

<template>
    <span
        class="flex items-center gap-1.5"
        :class="
            themed
                ? 'py-0.75'
                : [
                      'rounded-[7px] border bg-origin-border py-0.75 pr-2.25 pl-2',
                      variant.badgeClass,
                  ]
        "
        :style="variant.style"
    >
        <span class="size-1.5 rounded-full" :class="variant.dotClass" />
        <span
            class="text-xs/4"
            :class="[variant.textClass, !themed && 'font-medium']"
        >
            {{ environmentLabels[environment] }}
        </span>
    </span>
</template>
