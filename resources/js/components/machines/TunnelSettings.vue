<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import { appButtonClasses } from '@/lib/appButton';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { toast } from '@/lib/toast';

export type TunnelSettingsProps = {
    supported: boolean;
    since: string;
    current: {
        provider: string;
        tunnel_id: string | null;
        state: string;
        plain_http: boolean;
        since: string;
    } | null;
    target: string | null;
    changing: string | null;
};

const props = defineProps<{
    machineId: number;
    machineName: string;
    tunnel: TunnelSettingsProps;
    canManage: boolean;
}>();

const token = ref('');
const busy = ref<'up' | 'down' | null>(null);
const error = ref<string | null>(null);
const editing = ref(false);
let poll: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(poll));

const connected = computed(() => props.tunnel.current?.state === 'connected');
const showForm = computed(
    () => props.canManage && (!connected.value || editing.value),
);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function send(action: 'up' | 'down'): Promise<void> {
    if (busy.value) return;
    busy.value = action;
    error.value = null;

    try {
        const response = await fetch(`/machines/${props.machineId}/tunnel`, {
            method: action === 'up' ? 'POST' : 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body:
                action === 'up'
                    ? JSON.stringify({ token: token.value })
                    : undefined,
        });
        const body = await response.json().catch(() => ({}));
        if (response.status !== 202) {
            throw new Error(
                body.errors?.token?.[0] ??
                    body.error ??
                    body.message ??
                    trans('That didn’t work. Try again.'),
            );
        }
        await follow(body.jobId, action);
        token.value = '';
        editing.value = false;
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        busy.value = null;
    }
}

function follow(jobId: string, action: 'up' | 'down'): Promise<void> {
    let tries = 0;

    return new Promise((resolve, reject) => {
        const check = async () => {
            try {
                const response = await fetch(
                    `/machines/${props.machineId}/tunnel/${jobId}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                const job = await response.json();
                if (job.state === 'succeeded') {
                    toast.success(
                        action === 'up'
                            ? trans(
                                  ':name is connected to the tunnel. Its apps answer over plain HTTP, and Cloudflare does HTTPS.',
                                  { name: props.machineName },
                              )
                            : trans(
                                  'The tunnel is off :name. Its apps get their own certificates again.',
                                  { name: props.machineName },
                              ),
                    );
                    router.reload({
                        only: ['tunnel'],
                        onFinish: () => resolve(),
                    });

                    return;
                }
                if (
                    [
                        'failed',
                        'refused',
                        'timed_out',
                        'cancelled',
                        'stale',
                    ].includes(job.state)
                ) {
                    reject(
                        new Error(
                            job.error?.message ??
                                trans(
                                    'The machine didn’t change the tunnel. Try again.',
                                ),
                        ),
                    );

                    return;
                }
                // Installing cloudflared can take a few minutes.
                if (++tries >= 300) {
                    reject(
                        new Error(
                            trans(
                                'The machine is taking a while. The tunnel will show here when it’s done.',
                            ),
                        ),
                    );

                    return;
                }
                poll = setTimeout(check, 2000);
            } catch (e) {
                reject(e);
            }
        };
        poll = setTimeout(check, 1000);
    });
}

onMounted(() => {
    if (props.tunnel.changing) {
        busy.value = 'up';
        follow(props.tunnel.changing, connected.value ? 'down' : 'up')
            .catch((e) => {
                error.value = e instanceof Error ? e.message : String(e);
            })
            .finally(() => {
                busy.value = null;
            });
    }
});

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(trans('Copied.'));
    } catch {
        toast.error(trans('Couldn’t copy. Select it and copy it yourself.'));
    }
}
</script>

<template>
    <section class="ds-tile" :aria-label="$t('Cloudflare Tunnel')">
        <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-4">
            <div class="flex max-w-xl flex-col gap-0.5">
                <h2 class="text-[13px]/5 font-medium">
                    {{ $t('Cloudflare Tunnel') }}
                </h2>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'Serve the apps on :name through a Cloudflare Tunnel, so it needs no open ports for them. Cloudflare does HTTPS, and the machine answers each app’s domain over plain HTTP.',
                            { name: machineName },
                        ) +
                        ' '
                    }}
                </p>
            </div>
            <span
                v-if="connected"
                class="inline-flex items-center gap-1.5 rounded-full border border-(--ds-border) px-2 py-0.5 text-[12px]/5"
            >
                <span
                    class="size-1.5 rounded-full bg-(--ds-green)"
                    aria-hidden="true"
                />
                {{ $t('Connected') }}
            </span>
        </div>

        <div
            v-if="!tunnel.supported"
            class="border-t border-(--ds-border) px-5 py-3 text-[13px]/5 text-(--ds-text-muted)"
        >
            {{
                $t(
                    'Update the ServerOS daemon on :name to :version or later to use a tunnel.',
                    { name: machineName, version: tunnel.since },
                )
            }}
        </div>

        <template v-else>
            <div
                v-if="connected"
                class="flex flex-col gap-3 border-t border-(--ds-border) px-5 py-4 text-[13px]/5"
            >
                <p class="text-(--ds-text-muted)">
                    {{
                        $t(
                            'In Cloudflare, open the tunnel’s Routes and add a published application for each app’s domain (or one for *.yourdomain.com), with the service URL http://localhost:80. Cloudflare adds the DNS records for them.',
                        )
                    }}
                </p>
                <div
                    v-if="tunnel.target"
                    class="flex items-center justify-between gap-3 rounded-md border border-(--ds-border) bg-(--ds-card) px-3 py-2 font-ui-mono text-[12px]/5"
                >
                    <span class="min-w-0 truncate">
                        <span class="text-(--ds-text-muted)">CNAME → </span
                        >{{ tunnel.target }}
                    </span>
                    <button
                        type="button"
                        class="shrink-0 text-(--ds-text-muted) hover:text-(--ds-text)"
                        :aria-label="$t('Copy')"
                        @click="copy(tunnel.target)"
                    >
                        <Copy class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
                <p class="text-(--ds-text-muted)">
                    {{
                        $t(
                            'Deploy apps with their domain as usual. If you add DNS records yourself, make each one a proxied CNAME to the name above.',
                        )
                    }}
                </p>
                <div v-if="canManage && !editing" class="flex flex-wrap gap-2">
                    <AppButton
                        size="sm"
                        :disabled="busy !== null"
                        @click="editing = true"
                    >
                        {{ ' ' + $t('Use another token') + ' ' }}
                    </AppButton>
                    <button
                        type="button"
                        :disabled="busy !== null"
                        :class="
                            appButtonClasses(
                                'secondary',
                                'sm',
                                'border-(--ds-red)/30 text-(--ds-red) hover:border-(--ds-red)/60 hover:bg-(--ds-red)/10 disabled:opacity-50',
                            )
                        "
                        @click="send('down')"
                    >
                        <LoaderCircle
                            v-if="busy === 'down'"
                            class="size-3.5 animate-spin"
                            aria-hidden="true"
                        />
                        {{ ' ' + $t('Take the tunnel off') + ' ' }}
                    </button>
                </div>
            </div>

            <form
                v-if="showForm"
                class="flex flex-col gap-3 border-t border-(--ds-border) px-5 py-4 text-[13px]/5"
                @submit.prevent="send('up')"
            >
                <ol
                    v-if="!connected"
                    class="flex list-decimal flex-col gap-1 pl-4 text-(--ds-text-muted)"
                >
                    <li>
                        {{
                            $t(
                                'In Cloudflare, open Networking › Tunnels and create a tunnel with Cloudflared.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Copy its install command (or just the token at the end of it) and paste it below. ServerOS installs cloudflared on :name and runs it.',
                                { name: machineName },
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'In the tunnel’s Routes, add a published application for your apps, like *.example.com, with the service URL http://localhost:80.',
                            )
                        }}
                    </li>
                </ol>
                <label class="flex flex-col gap-1.5">
                    <span class="font-medium">{{ $t('Tunnel token') }}</span>
                    <Input
                        v-model="token"
                        type="password"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="
                            $t('eyJhIjoi… or the whole install command')
                        "
                        :disabled="busy !== null"
                    />
                </label>
                <p v-if="error" class="text-(--ds-red)" role="alert">
                    {{ error }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        :disabled="busy !== null || token.trim() === ''"
                        :class="appButtonClasses('primary', 'sm')"
                    >
                        <LoaderCircle
                            v-if="busy === 'up'"
                            class="size-3.5 animate-spin"
                            aria-hidden="true"
                        />
                        {{
                            ' ' +
                            (busy === 'up'
                                ? $t('Connecting…')
                                : $t('Connect the tunnel')) +
                            ' '
                        }}
                    </button>
                    <AppButton
                        v-if="editing"
                        size="sm"
                        :disabled="busy !== null"
                        @click="editing = false"
                    >
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                </div>
            </form>
            <p
                v-else-if="error"
                class="border-t border-(--ds-border) px-5 py-3 text-[13px]/5 text-(--ds-red)"
                role="alert"
            >
                {{ error }}
            </p>
        </template>
    </section>
</template>
