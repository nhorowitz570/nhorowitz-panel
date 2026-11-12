import type { App } from 'vue';

const DSN = import.meta.env.VITE_SENTRY_DSN as string | undefined;

const PATTERNS: [RegExp, string][] = [
    [/\bBearer\s+[\w.~+/=-]+/gi, 'Bearer [filtered]'],
    [
        /((?:pass(?:word)?|secret|token|api[_-]?key|auth|cookie|session|dsn|signature)["']?\s*[:=]\s*["']?)[^\s"'&,;)}]+/gi,
        '$1[filtered]',
    ],
    [
        /\b(?:sos[a-z]?_|sk_(?:live|test)_|whsec_|phc_|gh[pousr]_)[\w-]{8,}/g,
        '[filtered]',
    ],
    [/[\w.%+-]+@[\w.-]+\.[a-z]{2,}/gi, '[email]'],
    [/\b[a-f0-9]{40,}\b/gi, '[filtered]'],
];

export function scrub(text: string): string {
    return PATTERNS.reduce(
        (out, [pattern, mask]) => out.replace(pattern, mask),
        text,
    );
}

const quiet = new Set(['HttpNetworkError', 'HttpCancelledError']);

export function initErrorTracking(app: App): void {
    if (!DSN || typeof window === 'undefined') {
        return;
    }

    void import('@sentry/vue').then((Sentry) => {
        Sentry.init({
            app,
            dsn: DSN,
            environment: import.meta.env.MODE,
            release: import.meta.env.VITE_SENTRY_RELEASE as string | undefined,
            dataCollection: {
                userInfo: false,
                cookies: false,
                httpHeaders: false,
                httpBodies: [],
                urlQueryParams: false,
                stackFrameVariables: false,
            },
            tracesSampleRate: 0,
            denyUrls: [/cdn\.seline\.com/, /\/vendor\/seline\.js/],
            beforeSend(event) {
                if (
                    event.exception?.values?.some((exception) =>
                        quiet.has(exception.type ?? ''),
                    )
                ) {
                    return null;
                }
                event.message &&= scrub(event.message);
                for (const exception of event.exception?.values ?? []) {
                    exception.value &&= scrub(exception.value);
                }
                if (event.request?.url) {
                    event.request.url = event.request.url.split('?')[0];
                }
                delete event.user;

                return event;
            },
            beforeBreadcrumb(crumb) {
                crumb.message &&= scrub(crumb.message);
                if (typeof crumb.data?.url === 'string') {
                    crumb.data.url = crumb.data.url.split('?')[0];
                }

                return crumb;
            },
        });
    });
}
