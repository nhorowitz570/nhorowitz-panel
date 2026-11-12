import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';
import type { WorkspaceAccess, WorkspaceSummary } from '@/types/workspace';

declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            appUrl: string;
            statusUrl: string | null;
            brand: {
                company: string | null;
                terms: string | null;
                privacy: string | null;
                source: string | null;
            };
            docsUrl: string;
            feedbackUrl: string | null;
            promo: { code: string; offer: string } | null;
            cloudCredit?: string | null;
            upgrade?: { href: string; label: string } | null;
            auth: Auth;
            workspaces: WorkspaceSummary[];
            workspaceAccess: WorkspaceAccess | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
