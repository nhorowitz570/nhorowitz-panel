<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowUpRight, Check } from '@lucide/vue';
import {
    SolarBookOpen as BookOpen,
    SolarCircleHelp as CircleHelp,
    SolarLanguages as Languages,
    SolarLogOut as LogOut,
    SolarMessageSquare as MessageSquare,
    SolarMonitor as Monitor,
    SolarMoon as Moon,
    SolarSettings as Settings,
    SolarSparkles as Sparkles,
    SolarSun as Sun,
    SolarSunMoon as SunMoon,
} from '@/lib/solarIcons';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useWhatsNew } from '@/composables/useWhatsNew';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/composables/useAppearance';
import { getInitials } from '@/composables/useInitials';
import { docsUrl } from '@/lib/docs';
import { flagUrl } from '@/lib/flags';
import { changeLocale } from '@/lib/locale';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';

type Props = {
    display?: 'full' | 'avatar';
    side?: 'top' | 'right' | 'bottom';
    align?: 'start' | 'end';
};

const {
    display = 'full',
    side = 'top',
    align = 'start',
} = defineProps<Props>();

const page = usePage<{
    appUrl: string;
    staff?: { admin: boolean; sales: boolean };
    locale: string;
    locales: Record<string, string>;
    workspace?: { slug: string };
    extensions?: { nav: { key: string; label: string; path: string }[] } | null;
}>();
const user = computed(() => page.props.auth.user);
const support = computed(
    () =>
        page.props.extensions?.nav.find((item) => item.key === 'support') ??
        null,
);
const docs = computed(() => docsUrl(page.props.docsUrl));
const feedback = computed(() =>
    page.props.feedbackUrl
        ? `${page.props.feedbackUrl.replace(/\/$/, '')}/`
        : null,
);

const { appearance, updateAppearance } = useAppearance();
const whatsNew = useWhatsNew();

const themes = [
    { value: 'light', label: trans('Light'), icon: Sun },
    { value: 'dark', label: trans('Dark'), icon: Moon },
    { value: 'system', label: trans('System'), icon: Monitor },
] as const satisfies { value: Appearance; label: string; icon: unknown }[];

const row =
    'flex h-8 cursor-pointer items-center gap-2.5 rounded-[7px] px-2 text-[13.5px]/5 text-(--ds-text-secondary) data-highlighted:text-(--ds-text)';
const icon = 'size-4 shrink-0 text-(--ds-text-muted)';
const external = 'ml-auto size-3.5 text-(--ds-text-faint)';
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="flex cursor-pointer items-center gap-2 rounded-[10px] text-left hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-(--ds-hover)"
                :class="
                    display === 'full'
                        ? 'min-h-14 w-full px-1.5 py-2'
                        : 'size-10 shrink-0 justify-center'
                "
                :aria-label="
                    display === 'avatar'
                        ? $t('Account menu for :name', {
                              name: String(user.name),
                          })
                        : undefined
                "
                data-test="workspace-user-menu"
            >
                <span class="relative shrink-0">
                    <Avatar class="size-8 bg-(--ds-avatar)">
                        <AvatarImage
                            v-if="user.avatar"
                            :src="user.avatar"
                            :alt="user.name"
                            class="object-cover"
                        />
                        <AvatarFallback
                            class="bg-(--ds-avatar) text-[11px] font-medium text-(--ds-text)"
                        >
                            {{ getInitials(user.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <span
                        v-if="whatsNew.unread.value"
                        class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-(--ds-blue) ring-2 ring-(--ds-frame)"
                    >
                        <span class="sr-only">{{
                            $t('Something new in What’s new')
                        }}</span>
                    </span>
                </span>
                <span
                    v-if="display === 'full'"
                    class="flex min-w-0 flex-col gap-0.5 text-xs/4"
                >
                    <span class="truncate font-medium text-(--ds-text)">
                        {{ user.name }}
                    </span>
                    <span class="truncate text-(--ds-text-secondary)">
                        {{ user.email }}
                    </span>
                </span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            class="ds-modal min-w-56 rounded-[12px] p-1.5 font-ui"
            :class="{
                'w-(--reka-dropdown-menu-trigger-width)': display === 'full',
            }"
            :side="side"
            :align="align"
            :side-offset="6"
        >
            <DropdownMenuLabel class="flex min-w-0 flex-col px-2 py-1.5">
                <span class="truncate text-[13.5px]/5 font-medium">{{
                    user.name
                }}</span>
                <span
                    class="truncate text-[12.5px]/4 font-normal text-(--ds-text-muted)"
                    >{{ user.email }}</span
                >
            </DropdownMenuLabel>
            <DropdownMenuSeparator class="-mx-1.5 my-1.5" />
            <DropdownMenuItem as-child>
                <Link :href="edit()" prefetch :class="row">
                    <Settings :class="icon" :stroke-width="1.6" />
                    {{ $t('Account settings') }}
                </Link>
            </DropdownMenuItem>
            <DropdownMenuSub>
                <DropdownMenuSubTrigger :class="row">
                    <SunMoon :class="icon" :stroke-width="1.6" />
                    {{ $t('Theme') }}
                    <span class="ml-auto text-[13px] text-(--ds-text-faint)">{{
                        $t(
                            themes.find((theme) => theme.value === appearance)
                                ?.label ?? 'System',
                        )
                    }}</span>
                </DropdownMenuSubTrigger>
                <DropdownMenuSubContent class="ds-modal min-w-40 p-1.5 font-ui">
                    <DropdownMenuItem
                        v-for="theme in themes"
                        :key="theme.value"
                        :class="row"
                        @select="updateAppearance(theme.value)"
                    >
                        <component
                            :is="theme.icon"
                            :class="icon"
                            :stroke-width="1.6"
                        />
                        {{ $t(theme.label) }}
                        <Check
                            v-if="appearance === theme.value"
                            class="ml-auto size-3.5 text-(--ds-text)"
                            :stroke-width="2"
                        />
                    </DropdownMenuItem>
                </DropdownMenuSubContent>
            </DropdownMenuSub>
            <DropdownMenuSub>
                <DropdownMenuSubTrigger :class="row">
                    <Languages :class="icon" :stroke-width="1.6" />
                    {{ $t('Language') }}
                    <span class="ml-auto text-[13px] text-(--ds-text-faint)">{{
                        page.props.locales[page.props.locale]
                    }}</span>
                </DropdownMenuSubTrigger>
                <DropdownMenuSubContent class="ds-modal min-w-40 p-1.5 font-ui">
                    <DropdownMenuItem
                        v-for="(name, code) in page.props.locales"
                        :key="code"
                        :class="row"
                        @select="
                            page.props.locale !== code && changeLocale(code)
                        "
                    >
                        <img
                            :src="flagUrl(code)"
                            alt=""
                            class="h-2.5 w-3.75 rounded-[2px] object-cover"
                        />
                        {{ name }}
                        <Check
                            v-if="page.props.locale === code"
                            class="ml-auto size-3.5 text-(--ds-text)"
                            :stroke-width="2"
                        />
                    </DropdownMenuItem>
                </DropdownMenuSubContent>
            </DropdownMenuSub>

            <DropdownMenuSub>
                <DropdownMenuSubTrigger :class="row">
                    <CircleHelp :class="icon" :stroke-width="1.6" />
                    {{ $t('Help') }}
                    <span
                        v-if="whatsNew.unread.value"
                        class="ml-auto size-1.5 rounded-full bg-(--ds-blue)"
                        :aria-label="$t('New updates')"
                    />
                </DropdownMenuSubTrigger>
                <DropdownMenuSubContent class="ds-modal min-w-48 p-1.5 font-ui">
                    <DropdownMenuItem :class="row" @select="whatsNew.show()">
                        <Sparkles :class="icon" :stroke-width="1.6" />
                        {{ $t('What’s new') }}
                        <span
                            v-if="whatsNew.unread.value"
                            class="ml-auto rounded-full bg-(--ds-blue) px-1.5 text-[10.5px]/4 font-semibold text-white tabular-nums"
                            >{{
                                whatsNew.unread.value > 9
                                    ? '9+'
                                    : whatsNew.unread.value
                            }}</span
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="page.props.workspace && support"
                        as-child
                    >
                        <Link :href="support.path" :class="row">
                            <CircleHelp :class="icon" :stroke-width="1.6" />
                            {{ support.label }}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <a
                            :href="docs"
                            target="_blank"
                            rel="noopener"
                            :class="row"
                        >
                            <BookOpen :class="icon" :stroke-width="1.6" />
                            {{ $t('Docs') }}
                            <ArrowUpRight
                                :class="external"
                                :stroke-width="1.75"
                            />
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="feedback" as-child>
                        <a
                            :href="feedback"
                            target="_blank"
                            rel="noopener"
                            :class="row"
                        >
                            <MessageSquare :class="icon" :stroke-width="1.6" />
                            {{ $t('Feedback') }}
                            <ArrowUpRight
                                :class="external"
                                :stroke-width="1.75"
                            />
                        </a>
                    </DropdownMenuItem>
                </DropdownMenuSubContent>
            </DropdownMenuSub>
            <DropdownMenuSeparator class="-mx-1.5 my-1.5" />
            <DropdownMenuItem as-child>
                <Link
                    :href="logout()"
                    as="button"
                    :class="[row, 'w-full']"
                    data-test="logout-button"
                    @click="router.flushAll()"
                >
                    <LogOut :class="icon" :stroke-width="1.6" />
                    {{ $t('Log out') }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
