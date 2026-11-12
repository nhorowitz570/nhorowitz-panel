import '@/lib/polyfills';
import { installI18n } from '@/lib/i18n';
import { installFieldErrors } from '@/lib/fieldErrors';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { identify, initAnalytics } from '@/lib/analytics';
import type { Component } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AccountLayout from '@/layouts/AccountLayout.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import ViewingAsFrame from '@/layouts/ViewingAsFrame.vue';
import WorkspaceLayout from '@/layouts/WorkspaceLayout.vue';
import WorkspaceSettingsLayout from '@/layouts/workspace/WorkspaceSettingsLayout.vue';
import { initializeCelebrate } from '@/lib/celebrate';
import { initErrorTracking } from '@/lib/errorTracking';
import { initializeFlashToast } from '@/lib/flashToast';
import { resolvePanelPage } from '@/lib/pages';

const appName = import.meta.env.VITE_APP_NAME || 'ServerOS';

function pickLayout(name: string): Component | Component[] | null {
    switch (true) {
        case name.startsWith('errors/'):
        case name.startsWith('status/'):
        case name.startsWith('survey/'):
        case name.startsWith('welcome/'):
        case name.startsWith('install/'):
            return null;
        case name.startsWith('admin/'):
            return AdminLayout;
        case name.startsWith('auth/'):
            return AuthLayout;
        case name.startsWith('settings/'):
            return [AppLayout, SettingsLayout];
        case name.startsWith('workspace/settings/'):
            return [WorkspaceLayout, WorkspaceSettingsLayout];
        case name.startsWith('workspace/'):
            return WorkspaceLayout;
        case name.startsWith('workspaces/'):
            return AccountLayout;
        default:
            return AppLayout;
    }
}

void createInertiaApp({
    resolve: resolvePanelPage,
    title: (title, page) =>
        !title
            ? appName
            : page?.props.pageTitle && page.component === 'status/Show'
              ? title
              : `${title} - ${appName}`,
    layout: (name) => {
        const layout = pickLayout(name);

        return layout === null
            ? null
            : [ViewingAsFrame, ...(Array.isArray(layout) ? layout : [layout])];
    },
    withApp: (app, { page }) => {
        installI18n(
            app,
            typeof page.props.locale === 'string' ? page.props.locale : 'en',
        );
        installFieldErrors();
        initErrorTracking(app);
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

requestAnimationFrame(() => document.getElementById('page-outline')?.remove());

initializeTheme();

initializeFlashToast();

initializeCelebrate();

initAnalytics('panel');
router.on('navigate', (event) => {
    const user = (
        event.detail.page.props.auth as { user?: { id?: number } } | undefined
    )?.user;
    if (user?.id) identify(user.id);
});
