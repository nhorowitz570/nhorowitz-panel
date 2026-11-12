<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import LastUsedPill from '@/components/LastUsedPill.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import { appButtonClasses } from '@/lib/appButton';
import { loadGoogleIdentity } from '@/lib/googleIdentity';

const props = defineProps<{
    providers: string[];
    lastUsed?: string | null;
    googleClientId?: string | null;
}>();

const googleSlot = ref<HTMLDivElement | null>(null);
const googleReady = ref(false);
let observer: MutationObserver | null = null;

function sendCredential(credential: string): void {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/auth/google/credential';
    form.hidden = true;

    const fields: Record<string, string> = {
        _token:
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? '',
        credential,
    };
    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.append(input);
    }

    document.body.append(form);
    form.submit();
}

onMounted(() => {
    const slot = googleSlot.value;
    const clientId = props.googleClientId;
    if (!slot || !clientId) {
        return;
    }

    loadGoogleIdentity()
        .then((identity) => {
            if (!googleSlot.value) {
                return;
            }

            observer = new MutationObserver(() => {
                if (slot.querySelector('iframe')) {
                    googleReady.value = true;
                    observer?.disconnect();
                }
            });
            observer.observe(slot, { childList: true, subtree: true });

            identity.initialize({
                client_id: clientId,
                callback: (response) => {
                    if (response.credential) {
                        sendCredential(response.credential);
                    }
                },
                ux_mode: 'popup',
                use_fedcm_for_button: true,
            });
            identity.renderButton(slot, {
                type: 'standard',
                theme: document.documentElement.classList.contains('dark')
                    ? 'filled_black'
                    : 'outline',
                size: 'large',
                shape: 'rectangular',
                text: 'continue_with',
                logo_alignment: 'left',
                width: Math.min(400, Math.round(slot.offsetWidth)),
                locale: document.documentElement.lang || undefined,
            });
        })
        .catch(() => {});
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div v-if="providers.length" class="grid gap-2">
        <div
            v-if="providers.includes('google')"
            class="relative grid min-h-10 items-center"
        >
            <a
                v-show="!googleReady"
                href="/auth/google/redirect"
                :class="appButtonClasses('secondary', 'md', 'w-full')"
            >
                <ProviderLogo provider="google" class="size-4" />
                {{ $t('Continue with Google') + ' ' }}
                <LastUsedPill v-if="lastUsed === 'google'" />
            </a>
            <div
                v-if="googleClientId"
                ref="googleSlot"
                class="flex h-10 w-full justify-center [color-scheme:light]"
                :class="
                    googleReady
                        ? ''
                        : 'pointer-events-none absolute inset-0 opacity-0'
                "
                :aria-hidden="!googleReady"
            />
        </div>
        <a
            v-if="providers.includes('github')"
            href="/auth/github/redirect"
            :class="appButtonClasses('secondary', 'md', 'w-full')"
        >
            <ProviderLogo provider="github" class="size-4" />
            {{ $t('Continue with GitHub') + ' ' }}
            <LastUsedPill v-if="lastUsed === 'github'" />
        </a>
    </div>
</template>
