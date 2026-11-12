<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';
import ExtensionSlot from '@/components/ExtensionSlot.vue';
import { home } from '@/routes';

const slotParts = import.meta.glob<Component>(
    '../../../../vendor/serveroshq/*/resources/js/slots/layouts/AuthPanelLayout/**/*.vue',
    { eager: true, import: 'default' },
);

defineProps<{
    title?: string;
    description?: string;
}>();

const brand = usePage().props.brand;
const mark = 'url(/images/landing/serveros-logo.svg)';
</script>

<template>
    <div
        class="auth-page marketing-page ds-root flex min-h-svh bg-(--auth-ground) p-1.5 font-ui text-(--ds-text) antialiased"
    >
        <div
            class="flex min-w-0 flex-1 flex-col items-center justify-between gap-10 py-10 lg:py-14"
        >
            <Link
                :href="home()"
                :aria-label="$t('Back to ServerOS')"
                class="flex items-center gap-2 text-(--auth-ink) focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--ds-ring)"
            >
                <span
                    aria-hidden="true"
                    class="inline-block h-[17px] w-[15px] bg-current"
                    :style="{
                        maskImage: mark,
                        WebkitMaskImage: mark,
                        maskSize: '100% 100%',
                        WebkitMaskSize: '100% 100%',
                    }"
                />
                <span class="text-[16px]/5 font-semibold tracking-[-0.03em]"
                    >ServerOS</span
                >
            </Link>

            <main class="w-full max-w-[392px] px-6">
                <div
                    v-if="title || description"
                    class="mb-6 flex flex-col items-center gap-2 text-center"
                >
                    <h1
                        v-if="title"
                        class="text-[20px]/7 font-semibold tracking-[-0.01em] text-(--auth-ink)"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-[14px]/[21px] text-pretty text-(--auth-muted)"
                    >
                        {{ description }}
                    </p>
                </div>
                <div class="auth-form auth-compact">
                    <slot />
                </div>
                <p
                    v-if="brand.terms && brand.privacy"
                    class="mt-6 text-center text-[12px]/[18px] text-balance text-(--auth-faint)"
                >
                    {{ ' ' + $t('By continuing you agree to our') }}
                    <a
                        :href="brand.terms"
                        class="text-(--auth-link) underline decoration-(--auth-link-line) underline-offset-2 hover:text-(--auth-ink)"
                        >{{ $t('Terms') }}</a
                    >
                    {{ $t('and') }}
                    <a
                        :href="brand.privacy"
                        class="text-(--auth-link) underline decoration-(--auth-link-line) underline-offset-2 hover:text-(--auth-ink)"
                        >{{ $t('Privacy policy') }}</a
                    >.
                </p>
                <p
                    v-else-if="brand.terms || brand.privacy"
                    class="mt-6 text-center text-[12px]/[18px] text-balance text-(--auth-faint)"
                >
                    {{ ' ' + $t('By continuing you agree to our') }}
                    <a
                        :href="brand.terms ?? brand.privacy ?? undefined"
                        class="text-(--auth-link) underline decoration-(--auth-link-line) underline-offset-2 hover:text-(--auth-ink)"
                        >{{
                            brand.terms ? $t('Terms') : $t('Privacy policy')
                        }}</a
                    >.
                </p>
            </main>

            <footer class="text-[12px]/[14px] text-(--auth-faint)">
                <template v-if="brand.company">{{
                    `© ${new Date().getFullYear()} ${brand.company}`
                }}</template>
            </footer>
        </div>

        <ExtensionSlot name="layouts/AuthPanelLayout/art" :parts="slotParts" />
    </div>
</template>
