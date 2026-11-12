const SECRET_PATH =
    /^(\/(?:reset-password|invitations|_domain\/handoff|email\/verify|waitlist\/unsubscribe|status\/[^/]+\/(?:un)?subscribe))\/[^?#]+/;

const SUBSCRIPTION_PATH = /(\/(?:un)?subscribe)\/[^/?#]+/;

const KEPT_PARAM = /^utm_/;

export function scrubPath(path: string): string {
    return path
        .replace(SECRET_PATH, '$1/[redacted]')
        .replace(SUBSCRIPTION_PATH, '$1/[redacted]');
}

export function scrubUrl(value: string): string {
    if (value.startsWith('/')) {
        return scrubPath(value.split(/[?#]/)[0]);
    }
    if (!/^https?:\/\//i.test(value)) {
        return value;
    }
    try {
        const url = new URL(value);
        const kept = new URLSearchParams();
        url.searchParams.forEach((param, key) => {
            if (KEPT_PARAM.test(key)) kept.append(key, param);
        });
        url.search = kept.toString();
        url.hash = '';
        url.pathname = scrubPath(url.pathname);

        return url.toString();
    } catch {
        return value.split(/[?#]/)[0];
    }
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return !!value && typeof value === 'object' && !Array.isArray(value);
}

function scrubProperties(properties: Record<string, unknown>, depth = 0): void {
    for (const [key, value] of Object.entries(properties)) {
        if (typeof value === 'string') {
            if (/(url|referrer)$/i.test(key)) properties[key] = scrubUrl(value);
            else if (/pathname$/i.test(key)) properties[key] = scrubPath(value);
        } else if (
            depth < 2 &&
            key !== '$snapshot_data' &&
            key !== '$heatmap_data' &&
            isRecord(value)
        ) {
            scrubProperties(value, depth + 1);
        }
    }
    const heatmap = properties.$heatmap_data;
    if (heatmap && typeof heatmap === 'object') {
        properties.$heatmap_data = Object.fromEntries(
            Object.entries(heatmap).map(([url, data]) => [scrubUrl(url), data]),
        );
    }
}

type Captured = {
    properties?: Record<string, unknown>;
    $set?: Record<string, unknown>;
    $set_once?: Record<string, unknown>;
};

export function scrubEvent<T extends Captured | null>(event: T): T {
    if (!event) return event;
    for (const bag of [event.properties, event.$set, event.$set_once]) {
        if (bag) scrubProperties(bag);
    }

    return event;
}

export function scrubRequest<
    T extends { name?: string; requestBody?: unknown; responseBody?: unknown },
>(request: T): T {
    if (request.name) request.name = scrubUrl(request.name);
    delete request.requestBody;
    delete request.responseBody;

    return request;
}
