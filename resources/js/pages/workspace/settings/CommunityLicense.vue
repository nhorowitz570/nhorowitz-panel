<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import Tile from '@/components/workspace/Tile.vue';
import { SolarCopy as Copy, SolarKey as Key } from '@/lib/solarIcons';
import { toast } from '@/lib/toast';
import type { Workspace } from '@/types';

const props = defineProps<{
    workspace: Workspace;
    paidFeatures: string[];
    image: string;
    docker: boolean;
}>();

const featureLabels: Record<string, string> = {
    sso: 'Single sign-on',
    scim: 'SCIM provisioning',
    custom_roles: 'Custom roles',
    custom_domains: 'Your own domains',
    own_log_storage: 'Your own log storage',
    fleet_status_page: 'A status page for every machine',
};

const imageName = computed(() => props.image.replace(/:[^:/]+$/, ''));
const envLine = computed(() => `SERVEROS_IMAGE=${props.image}`);
const upCommand = 'serveros-panel upgrade';

const copied = ref<string | null>(null);
async function copy(text: string, what: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = what;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        toast.error(trans('Couldn’t copy; select it and copy by hand.'));
    }
}

const pre =
    'overflow-x-auto rounded-lg border border-(--ds-border) bg-(--ds-field) py-2.5 pr-11 pl-3.5 font-ui-mono text-[12px]/5';
const copyButton =
    'absolute top-1.5 right-1.5 flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const muted = 'text-[13px]/5 text-(--ds-text-muted)';
</script>

<template>
    <Head :title="$t('License')" />

    <Tile
        :title="$t('License')"
        :description="
            $t(
                'This install is the community edition of ServerOS, built from its open source code (AGPL-3.0). It’s free and has everything except the paid features.',
            )
        "
        :icon="Key"
        :padded="false"
    >
        <template #actions>
            <span
                class="rounded-full bg-(--ds-active) px-2 py-px text-[11.5px]/4 font-medium text-(--ds-text-muted)"
                >{{ $t('Community edition') }}</span
            >
        </template>

        <div class="ds-tile-rows">
            <SettingsLine
                :label="$t('Paid features')"
                :hint="
                    $t(
                        'They come with the official ServerOS image and turn on with a license key.',
                    )
                "
            >
                <ul class="flex flex-col gap-1 text-[13px]/5 md:pt-1.5">
                    <li v-for="feature in paidFeatures" :key="feature">
                        {{ $t(featureLabels[feature] ?? feature) }}
                    </li>
                </ul>
            </SettingsLine>

            <SettingsLine
                :label="$t('Switch to the official image')"
                :hint="
                    docker
                        ? $t(
                              'Your workspaces, machines and data stay as they are. Without a key it works just like this install.',
                          )
                        : $t('The official image runs with Docker.')
                "
            >
                <template v-if="docker">
                    <p :class="muted" class="md:pt-1.5">
                        {{
                            $t(
                                'In the install’s folder (/opt/serveros unless you picked another), set this in .env:',
                            )
                        }}
                    </p>
                    <div class="relative">
                        <pre :class="pre">{{ envLine }}</pre>
                        <button
                            type="button"
                            :class="copyButton"
                            :aria-label="$t('Copy')"
                            @click="copy(envLine, 'env')"
                        >
                            <component
                                :is="copied === 'env' ? Check : Copy"
                                class="size-3.5"
                            />
                        </button>
                    </div>
                    <p :class="muted">
                        {{
                            $t(
                                'Then run this. It backs up first, then starts on the new image:',
                            )
                        }}
                    </p>
                    <div class="relative">
                        <pre :class="pre">{{ upCommand }}</pre>
                        <button
                            type="button"
                            :class="copyButton"
                            :aria-label="$t('Copy')"
                            @click="copy(upCommand, 'up')"
                        >
                            <component
                                :is="copied === 'up' ? Check : Copy"
                                class="size-3.5"
                            />
                        </button>
                    </div>
                    <p :class="muted">
                        {{
                            $t('Or run install.sh again with --image=:image.', {
                                image: imageName,
                            })
                        }}
                    </p>
                </template>
                <p v-else :class="muted" class="md:pt-1.5">
                    {{
                        $t(
                            'This install runs from source without Docker. For the paid features, install ServerOS with Docker and the official image (:image), as the self-hosting guide shows.',
                            { image: imageName },
                        )
                    }}
                </p>
            </SettingsLine>

            <SettingsLine
                :label="$t('Add your key')"
                :hint="$t('From your ServerOS account.')"
            >
                <p class="text-[13px]/5 md:pt-1.5">
                    {{
                        $t(
                            'Once it runs the official image, come back here to Settings › License and paste your license key. That’s all: nothing to reinstall.',
                        )
                    }}
                </p>
            </SettingsLine>
        </div>
    </Tile>
</template>
