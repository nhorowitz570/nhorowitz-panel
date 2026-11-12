import type { DefineComponent } from 'vue';

type PageModule = { default: DefineComponent };
type PageLoader = () => Promise<PageModule>;

const own = import.meta.glob<PageModule>('../pages/**/*.vue');

const fromPackages = import.meta.glob<PageModule>(
    '../../../vendor/serveroshq/*/resources/js/pages/**/*.vue',
);

const PACKAGE_PAGE = /\/resources\/js\/pages\/(.+)\.vue$/;

const pages: Record<string, PageLoader> = {};

for (const [path, load] of Object.entries(fromPackages)) {
    const name = PACKAGE_PAGE.exec(path)?.[1];

    if (name) {
        pages[name] = load;
    }
}

for (const [path, load] of Object.entries(own)) {
    pages[path.slice('../pages/'.length, -'.vue'.length)] = load;
}

export async function resolvePanelPage(name: string): Promise<DefineComponent> {
    const load = pages[name];

    if (!load) {
        throw new Error(`Page not found: ${name}`);
    }

    return (await load()).default;
}
