export type GoogleIdentity = {
    initialize(options: {
        client_id: string;
        callback: (response: { credential?: string }) => void;
        ux_mode?: 'popup' | 'redirect';
        use_fedcm_for_button?: boolean;
    }): void;
    renderButton(
        parent: HTMLElement,
        options: {
            type: 'standard' | 'icon';
            theme?: 'outline' | 'filled_blue' | 'filled_black';
            size?: 'large' | 'medium' | 'small';
            shape?: 'rectangular' | 'pill';
            text?: 'signin_with' | 'signup_with' | 'continue_with' | 'signin';
            logo_alignment?: 'left' | 'center';
            width?: number;
            locale?: string;
        },
    ): void;
};

declare global {
    interface Window {
        google?: { accounts?: { id?: GoogleIdentity } };
    }
}

let loading: Promise<GoogleIdentity> | null = null;

export function loadGoogleIdentity(): Promise<GoogleIdentity> {
    loading ??= new Promise<GoogleIdentity>((resolve, reject) => {
        const ready = () => {
            const identity = window.google?.accounts?.id;
            if (identity) {
                resolve(identity);
            } else {
                reject(new Error('Google Identity Services did not load'));
            }
        };

        if (window.google?.accounts?.id) {
            ready();

            return;
        }

        const script = document.createElement('script');
        script.src = 'https://accounts.google.com/gsi/client';
        script.async = true;
        script.defer = true;
        script.onload = ready;
        script.onerror = () => {
            loading = null;
            reject(new Error('Google Identity Services was blocked'));
        };
        document.head.append(script);
    });

    return loading;
}
