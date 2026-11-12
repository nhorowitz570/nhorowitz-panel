export type Category = 'analytics' | 'marketing';

export type Consent = {
    version: number;
    at: string;
    analytics: boolean;
    marketing: boolean;
};

export const CONSENT_VERSION = 2;

export const CONSENT_EVENT = 'serveros:consent';

export const OPEN_SETTINGS_EVENT = 'serveros:cookie-settings';

const COOKIE = 'cookie_consent';

const MAX_AGE = 60 * 60 * 24 * 182;

function shared(): string {
    const host = window.location.hostname;
    const parts = host.split('.');
    if (parts.length < 2 || /^[\d.]+$/.test(host)) return '';

    return `;domain=.${parts.slice(-2).join('.')}`;
}

export function readConsent(): Consent | null {
    try {
        const raw = document.cookie
            .split('; ')
            .find((part) => part.startsWith(`${COOKIE}=`))
            ?.slice(COOKIE.length + 1);
        if (!raw) return null;
        const consent = JSON.parse(decodeURIComponent(raw)) as Consent;
        return consent.version === CONSENT_VERSION ? consent : null;
    } catch {
        return null;
    }
}

export function saveConsent(choice: Record<Category, boolean>): Consent {
    const consent: Consent = {
        version: CONSENT_VERSION,
        at: new Date().toISOString(),
        ...choice,
    };
    const secure = window.location.protocol === 'https:' ? ';Secure' : '';
    document.cookie = `${COOKIE}=${encodeURIComponent(JSON.stringify(consent))};path=/;max-age=${MAX_AGE};SameSite=Lax${shared()}${secure}`;
    window.dispatchEvent(new CustomEvent(CONSENT_EVENT, { detail: consent }));
    return consent;
}

export function hasConsent(category: Category): boolean {
    return readConsent()?.[category] === true;
}

export function openCookieSettings(): void {
    window.dispatchEvent(new Event(OPEN_SETTINGS_EVENT));
}
