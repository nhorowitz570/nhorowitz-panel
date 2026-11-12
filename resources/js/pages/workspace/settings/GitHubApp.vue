<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import Tile from '@/components/workspace/Tile.vue';
import { appButtonClasses } from '@/lib/appButton';
import {
    SolarCopy as Copy,
    SolarGitBranch as GitBranch,
    SolarKey as Key,
} from '@/lib/solarIcons';
import { toast } from '@/lib/toast';
import type { Workspace } from '@/types';

type Props = {
    workspace: Workspace;
    app: {
        source: 'environment' | 'settings';
        name: string | null;
        owner: string | null;
        url: string | null;
        id: string | null;
    } | null;
    connected: number;
    urls: {
        homepage: string;
        callback: string;
        webhook: string;
        created: string;
    };
    manifest: Record<string, unknown>;
    webhooks: boolean;
    state: string;
    defaultName: string;
    webhookSecret: string;
};

const props = defineProps<Props>();

const form = useForm({
    app_id: '',
    client_id: '',
    client_secret: '',
    private_key: '',
    webhook_secret: props.webhooks ? props.webhookSecret : '',
});

const name = ref(props.defaultName);
const owner = ref<'personal' | 'organisation'>('personal');
const organisation = ref('');
const anyone = ref(false);
const manifestForm = ref<HTMLFormElement | null>(null);
const showManual = ref(false);
const copied = ref<string | null>(null);

const organisationValid = computed(() =>
    /^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/.test(organisation.value.trim()),
);
const nameValid = computed(() => {
    const value = name.value.trim();

    return value.length > 0 && value.length <= 34;
});
const canCreate = computed(
    () =>
        nameValid.value &&
        (owner.value === 'personal' || organisationValid.value),
);
const manifestAction = computed(() => {
    const state = encodeURIComponent(props.state);

    return owner.value === 'organisation'
        ? `https://github.com/organizations/${encodeURIComponent(organisation.value.trim())}/settings/apps/new?state=${state}`
        : `https://github.com/settings/apps/new?state=${state}`;
});
const manifestJson = computed(() =>
    JSON.stringify({
        ...props.manifest,
        name: name.value.trim(),
        public: anyone.value,
    }),
);
const newAppUrl = computed(() =>
    owner.value === 'organisation' && organisationValid.value
        ? `https://github.com/organizations/${encodeURIComponent(organisation.value.trim())}/settings/apps/new`
        : 'https://github.com/settings/apps/new',
);

const values = computed(() => [
    {
        key: 'homepage',
        label: trans('Homepage URL'),
        value: props.urls.homepage,
    },
    {
        key: 'callback',
        label: trans('Callback URL'),
        value: props.urls.callback,
    },
    ...(props.webhooks
        ? [
              {
                  key: 'webhook',
                  label: trans('Webhook URL'),
                  value: props.urls.webhook,
              },
              {
                  key: 'secret',
                  label: trans('Webhook secret'),
                  value: form.webhook_secret,
              },
          ]
        : []),
]);

function createOnGitHub(): void {
    if (!canCreate.value) {
        return;
    }
    manifestForm.value?.submit();
}

function save(): void {
    form.put('/settings/github/app', { preserveScroll: true });
}

function readKey(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) {
        return;
    }
    const reader = new FileReader();
    reader.onload = () => {
        form.private_key = String(reader.result ?? '');
    };
    reader.readAsText(file);
}

function remove(): void {
    if (
        !window.confirm(
            trans(
                'Remove this GitHub App from ServerOS? Every workspace’s GitHub connection goes with it, and private repositories can’t be deployed until you set one up again.',
            ),
        )
    ) {
        return;
    }
    router.delete('/settings/github/app', { preserveScroll: true });
}

async function copy(text: string, what: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = what;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        toast.error(trans('Couldn’t copy; select it and copy by hand.'));
    }
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const muted = 'text-[13px]/5 text-(--ds-text-muted)';
const code =
    'block min-w-0 grow truncate rounded-md border border-(--ds-border) bg-(--ds-field) px-2.5 py-1.5 font-ui-mono text-[12px]/5';
const copyButton =
    'flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) ' +
    focusRing;
const textarea =
    'w-full rounded-md border border-input bg-(--ds-field) px-3 py-2 font-ui-mono text-[12px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus:border-ring focus:ring-[3px] focus:ring-ring/50';
</script>

<template>
    <Head :title="$t('GitHub App')" />

    <template v-if="app">
        <Tile
            :title="$t('GitHub App')"
            :description="
                app.source === 'environment'
                    ? $t(
                          'This install’s GitHub App is set with GITHUB_APP_* in .env, so it’s changed there, not here.',
                      )
                    : $t(
                          'The app this install uses to read your repositories and hear about pushes. Every workspace here shares it.',
                      )
            "
            :icon="GitBranch"
            :padded="false"
        >
            <div class="ds-tile-rows">
                <SettingsLine :label="$t('App')">
                    <p class="text-[13px]/5 md:pt-1.5">
                        <a
                            v-if="app.url"
                            :href="app.url"
                            target="_blank"
                            rel="noopener"
                            class="font-medium underline decoration-(--ds-border-strong) underline-offset-2 hover:decoration-current"
                            >{{ app.name }}</a
                        >
                        <span v-else class="font-medium">{{ app.name }}</span>
                        <span v-if="app.owner" :class="muted">
                            {{
                                ' ' +
                                $t('owned by :owner', { owner: app.owner })
                            }}
                        </span>
                    </p>
                    <p v-if="app.id" :class="muted">
                        {{ $t('App ID :id', { id: app.id }) }}
                    </p>
                </SettingsLine>
                <SettingsLine
                    :label="$t('Connect your GitHub account')"
                    :hint="
                        $t(
                            'Install the app on your account or organisation and choose the repositories ServerOS may read. GitHub then asks you to authorize ServerOS, which confirms you manage that account.',
                        )
                    "
                >
                    <p :class="muted" class="md:pt-1.5">
                        {{
                            connected > 0
                                ? $t(
                                      'This workspace has :count connected. Manage them in Settings › General.',
                                      { count: String(connected) },
                                  )
                                : $t('This workspace hasn’t connected one yet.')
                        }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <a
                            href="/settings/github/connect"
                            :class="appButtonClasses('primary', 'sm')"
                        >
                            {{
                                connected > 0
                                    ? $t('Connect another account')
                                    : $t('Connect GitHub')
                            }}
                        </a>
                        <Link
                            href="/settings/general#github"
                            :class="appButtonClasses('quiet', 'sm')"
                        >
                            {{ $t('Back to settings') }}
                        </Link>
                    </div>
                </SettingsLine>
                <SettingsLine
                    v-if="app.source === 'settings'"
                    :label="$t('Remove')"
                    :hint="
                        $t(
                            'Disconnects every workspace from GitHub. The app stays on GitHub until you delete it there.',
                        )
                    "
                >
                    <form class="md:pt-0.5" @submit.prevent="remove">
                        <DangerButton :label="$t('Remove GitHub App')" />
                    </form>
                </SettingsLine>
            </div>
        </Tile>
    </template>

    <template v-else>
        <Tile
            :title="$t('Set up GitHub')"
            :description="
                $t(
                    'ServerOS needs a GitHub App of its own to deploy private repositories and to deploy when you push. You make it once for this install, and every workspace here can use it.',
                )
            "
            :icon="GitBranch"
            :padded="false"
        >
            <div class="ds-tile-rows">
                <SettingsLine
                    :label="$t('Name')"
                    :hint="
                        $t(
                            'What GitHub shows when it asks for access. It has to be unique on GitHub, 34 characters at most.',
                        )
                    "
                    input-id="github-app-name"
                >
                    <Input
                        id="github-app-name"
                        v-model="name"
                        maxlength="34"
                        class="max-w-sm"
                    />
                </SettingsLine>
                <SettingsLine
                    :label="$t('Owner')"
                    :hint="
                        $t(
                            'Make it on the account that owns your repositories. If they’re in an organisation, pick it, so the app can be installed there.',
                        )
                    "
                >
                    <div
                        class="ds-segments w-fit"
                        role="group"
                        :aria-label="$t('Owner')"
                    >
                        <button
                            type="button"
                            class="cursor-pointer"
                            :class="focusRing"
                            :aria-pressed="owner === 'personal'"
                            @click="owner = 'personal'"
                        >
                            {{ $t('My account') }}
                        </button>
                        <button
                            type="button"
                            class="cursor-pointer"
                            :class="focusRing"
                            :aria-pressed="owner === 'organisation'"
                            @click="owner = 'organisation'"
                        >
                            {{ $t('An organisation') }}
                        </button>
                    </div>
                    <Input
                        v-if="owner === 'organisation'"
                        v-model="organisation"
                        :placeholder="$t('Organisation name, like acme')"
                        :aria-label="$t('Organisation name')"
                        class="max-w-sm"
                    />
                    <label class="flex items-start gap-2 text-[13px]/5">
                        <Checkbox
                            v-model="anyone"
                            class="mt-0.5"
                            :aria-label="
                                $t('Any GitHub account can install it')
                            "
                        />
                        <span>
                            {{ $t('Any GitHub account can install it') }}
                            <span :class="muted" class="block">
                                {{
                                    $t(
                                        'Leave this off unless your repositories are spread over several accounts. Off, only the owner can install it.',
                                    )
                                }}
                            </span>
                        </span>
                    </label>
                </SettingsLine>
                <SettingsLine
                    :label="$t('Create it')"
                    :hint="
                        $t(
                            'GitHub opens with the app filled in: its permissions and where to send you back. Check it, click Create GitHub App, and you’ll come straight back here.',
                        )
                    "
                >
                    <div class="flex flex-wrap items-center gap-3 md:pt-0.5">
                        <AppButton
                            size="sm"
                            variant="primary"
                            :disabled="!canCreate"
                            @click="createOnGitHub"
                        >
                            {{ $t('Create on GitHub') }}
                        </AppButton>
                        <button
                            type="button"
                            class="cursor-pointer rounded-md text-[13px]/5 text-(--ds-text-muted) underline decoration-(--ds-border-strong) underline-offset-2 hover:text-(--ds-text)"
                            :class="focusRing"
                            @click="showManual = !showManual"
                        >
                            {{
                                showManual
                                    ? $t('Hide the steps')
                                    : $t('Or set it up by hand')
                            }}
                        </button>
                    </div>
                    <p :class="muted">
                        {{
                            webhooks
                                ? $t(
                                      'GitHub sends pushes to :url, so deploy on push needs this panel reachable from the internet. Private repositories deploy either way.',
                                      { url: urls.webhook },
                                  )
                                : $t(
                                      'This panel’s address (:url) isn’t reachable from the internet, and GitHub won’t send webhooks to it. The app is made without one: private repositories deploy, but not on push.',
                                      { url: urls.homepage },
                                  )
                        }}
                    </p>
                </SettingsLine>
            </div>
        </Tile>

        <form
            ref="manifestForm"
            :action="manifestAction"
            method="post"
            class="hidden"
        >
            <input type="hidden" name="manifest" :value="manifestJson" />
        </form>

        <Tile
            v-if="showManual"
            :title="$t('Set it up by hand')"
            :description="
                $t(
                    'For when you’d rather make the app yourself. It’s the same app the button makes.',
                )
            "
            :icon="Key"
            :padded="false"
        >
            <div class="ds-tile-rows">
                <SettingsLine :label="$t('1. Start a new app')">
                    <p class="text-[13px]/5 md:pt-1.5">
                        {{
                            $t(
                                'On GitHub, open Settings › Developer settings › GitHub Apps › New GitHub App. For an organisation, start from its settings instead.',
                            )
                        }}
                    </p>
                    <div>
                        <a
                            :href="newAppUrl"
                            target="_blank"
                            rel="noopener"
                            :class="appButtonClasses('secondary', 'sm')"
                        >
                            {{ $t('Open GitHub') }}
                        </a>
                    </div>
                </SettingsLine>
                <SettingsLine
                    :label="$t('2. Fill in these')"
                    :hint="
                        webhooks
                            ? $t(
                                  'Give it any name. Under Identifying and authorizing users, tick Request user authorization (OAuth) during installation. Keep the webhook active.',
                              )
                            : $t(
                                  'Give it any name. Under Identifying and authorizing users, tick Request user authorization (OAuth) during installation. Under Webhook, untick Active: GitHub can’t reach this panel.',
                              )
                    "
                >
                    <div
                        v-for="item in values"
                        :key="item.key"
                        class="flex flex-col gap-1"
                    >
                        <span class="text-xs/4 text-(--ds-text-muted)">{{
                            item.label
                        }}</span>
                        <div class="flex items-center gap-1.5">
                            <code :class="code">{{ item.value }}</code>
                            <button
                                type="button"
                                :class="copyButton"
                                :aria-label="
                                    $t('Copy :what', { what: item.label })
                                "
                                @click="copy(item.value, item.key)"
                            >
                                <component
                                    :is="copied === item.key ? Check : Copy"
                                    class="size-3.5"
                                />
                            </button>
                        </div>
                    </div>
                </SettingsLine>
                <SettingsLine :label="$t('3. Permissions and events')">
                    <ul class="flex flex-col gap-1 text-[13px]/5 md:pt-1.5">
                        <li>
                            {{
                                $t(
                                    'Repository permissions: Contents and Pull requests set to Read and write. Metadata stays Read-only.',
                                )
                            }}
                        </li>
                        <li v-if="webhooks">
                            {{
                                $t(
                                    'Subscribe to events: Push and Pull request.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Where can this app be installed: Only on this account, unless your repositories are spread over several. Then click Create GitHub App.',
                                )
                            }}
                        </li>
                    </ul>
                </SettingsLine>
                <SettingsLine
                    :label="$t('4. Copy its keys here')"
                    :hint="
                        $t(
                            'On the app’s page: the App ID and Client ID are at the top. Click Generate a new client secret, then Generate a private key, which downloads a .pem file.',
                        )
                    "
                >
                    <form class="flex flex-col gap-3" @submit.prevent="save">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex flex-col gap-1">
                                <span
                                    class="text-xs/4 text-(--ds-text-muted)"
                                    >{{ $t('App ID') }}</span
                                >
                                <Input
                                    v-model="form.app_id"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    placeholder="123456"
                                />
                                <InputError :message="form.errors.app_id" />
                            </label>
                            <label class="flex flex-col gap-1">
                                <span
                                    class="text-xs/4 text-(--ds-text-muted)"
                                    >{{ $t('Client ID') }}</span
                                >
                                <Input
                                    v-model="form.client_id"
                                    autocomplete="off"
                                    placeholder="Iv23li…"
                                />
                                <InputError :message="form.errors.client_id" />
                            </label>
                        </div>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs/4 text-(--ds-text-muted)">{{
                                $t('Client secret')
                            }}</span>
                            <Input
                                v-model="form.client_secret"
                                type="password"
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.client_secret" />
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs/4 text-(--ds-text-muted)">{{
                                $t('Private key')
                            }}</span>
                            <textarea
                                v-model="form.private_key"
                                rows="4"
                                spellcheck="false"
                                autocomplete="off"
                                :class="textarea"
                                placeholder="-----BEGIN RSA PRIVATE KEY-----"
                            />
                            <span :class="muted">
                                {{ $t('Paste it, or choose the .pem file:') }}
                                <input
                                    type="file"
                                    accept=".pem,application/x-pem-file,text/plain"
                                    class="mt-1 block text-[12px]/5 file:mr-2 file:cursor-pointer file:rounded-md file:border file:border-(--ds-border) file:bg-(--ds-field) file:px-2 file:py-1 file:text-[12px] file:text-(--ds-text)"
                                    @change="readKey"
                                />
                            </span>
                            <InputError :message="form.errors.private_key" />
                        </label>
                        <label v-if="webhooks" class="flex flex-col gap-1">
                            <span class="text-xs/4 text-(--ds-text-muted)">{{
                                $t('Webhook secret')
                            }}</span>
                            <Input
                                v-model="form.webhook_secret"
                                autocomplete="off"
                            />
                            <span :class="muted">{{
                                $t(
                                    'The one you gave GitHub in step 2. We made this one up for you.',
                                )
                            }}</span>
                            <InputError :message="form.errors.webhook_secret" />
                        </label>
                        <div>
                            <SubmitButton
                                :label="$t('Check and save')"
                                :busy-label="$t('Checking with GitHub…')"
                                :processing="form.processing"
                            />
                        </div>
                    </form>
                </SettingsLine>
            </div>
        </Tile>
    </template>
</template>
