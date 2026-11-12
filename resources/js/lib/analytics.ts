import type { PostHog } from 'posthog-js';
import { scrubEvent, scrubRequest } from '@/lib/analyticsPrivacy';
import { CONSENT_EVENT, hasConsent } from '@/lib/consent';
import type { Consent } from '@/lib/consent';

const KEY = import.meta.env.VITE_POSTHOG_KEY as string | undefined;

let client: PostHog | null = null;
let loading: Promise<PostHog | null> | null = null;
const queued: [string, Record<string, unknown>][] = [];

async function start(): Promise<PostHog | null> {
    if (!KEY) return null;
    if (client) {
        client.opt_in_capturing();
        return client;
    }
    loading ??= import('posthog-js').then(({ default: posthog }) => {
        posthog.init(KEY, {
            api_host:
                (import.meta.env.VITE_POSTHOG_HOST as string | undefined) ??
                'https://eu.i.posthog.com',
            ui_host:
                (import.meta.env.VITE_POSTHOG_UI_HOST as string | undefined) ??
                'https://eu.posthog.com',
            capture_pageview: 'history_change',
            capture_pageleave: true,
            enable_heatmaps: true,
            person_profiles: 'identified_only',
            mask_all_text: source === 'panel',
            mask_all_element_attributes: source === 'panel',
            before_send: (event) => scrubEvent(event),
            session_recording: {
                maskAllInputs: true,
                maskTextSelector: source === 'panel' ? '*' : null,
                maskCapturedNetworkRequestFn: (request) =>
                    scrubRequest(request),
            },
        });
        posthog.register({ app: source });
        client = posthog;
        for (const [event, properties] of queued.splice(0)) {
            posthog.capture(event, properties);
        }
        return posthog;
    });

    return loading;
}

function stop(): void {
    if (!client) return;
    client.opt_out_capturing();
    client.reset();
}

let source: 'site' | 'panel' = 'site';

export function initAnalytics(app: 'site' | 'panel'): void {
    source = app;
    if (!KEY || typeof window === 'undefined') return;
    if (hasConsent('analytics')) void start();
    window.addEventListener(CONSENT_EVENT, (event) => {
        if ((event as CustomEvent<Consent>).detail.analytics) void start();
        else stop();
    });
}

export function track(
    event: string,
    properties: Record<string, unknown> = {},
): void {
    if (!KEY || !hasConsent('analytics')) return;
    if (client) client.capture(event, properties);
    else {
        queued.push([event, properties]);
        void start();
    }
}

export function identify(userId: number | string): void {
    if (!KEY || !hasConsent('analytics')) return;
    void start().then((posthog) => posthog?.identify(String(userId)));
}
