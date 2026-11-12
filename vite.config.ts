import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import type { FontDefinition } from 'laravel-vite-plugin/fonts';
import { sentryVitePlugin } from '@sentry/vite-plugin';
import {
    copyFileSync,
    existsSync,
    lstatSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    realpathSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { basename, dirname, join, resolve } from 'node:path';
import { defineConfig, lazyPlugins, loadEnv } from 'vite-plus';
import type { Plugin } from 'vite-plus';

function webFont(
    family: string,
    weights: number[],
    preload: number[] = [],
    dir = 'resources/fonts/web',
): FontDefinition {
    const alias = family.toLowerCase().replaceAll(' ', '-');

    return local(family, {
        alias,
        variants: weights.map((weight) => ({
            src: `${dir}/${alias}-${weight}.woff2`,
            weight,
        })),
        preload:
            preload.length > 0 ? preload.map((weight) => ({ weight })) : false,
        display: 'swap',
        optimizedFallbacks: false,
    });
}

function simpleIconsPerIcon(): Plugin {
    const prefix = 'virtual:simple-icon/';
    let icons: Record<string, Record<string, unknown>> | null = null;

    return {
        name: 'serveros:simple-icons-per-icon',
        apply: 'build',
        enforce: 'pre',
        transform(code, id) {
            if (
                id.includes('/node_modules/') ||
                !/\.(?:ts|tsx|vue)$/.test(id) ||
                !code.includes('simple-icons')
            ) {
                return null;
            }

            const out = code.replace(
                /import\s*\{([^}]*)\}\s*from\s*['"]simple-icons['"];?/g,
                (statement, names: string) => {
                    const kept: string[] = [];
                    const lines: string[] = [];
                    for (const name of names.split(',').map((n) => n.trim())) {
                        const [imported, local = imported] =
                            name.split(/\s+as\s+/);
                        if (/^si[A-Z0-9]/.test(imported)) {
                            lines.push(
                                `import ${local} from '${prefix}${imported}';`,
                            );
                        } else if (name) {
                            kept.push(name);
                        }
                    }
                    if (kept.length > 0) {
                        lines.unshift(
                            `import { ${kept.join(', ')} } from 'simple-icons';`,
                        );
                    }
                    const breaks = statement.split('\n').length - 1;

                    return lines.join(' ') + '\n'.repeat(breaks);
                },
            );

            return out === code ? null : { code: out, map: null };
        },
        resolveId(id) {
            return id.startsWith(prefix) ? `\0${id}` : null;
        },
        async load(id) {
            if (!id.startsWith(`\0${prefix}`)) return null;
            icons ??= (await import('simple-icons')) as Record<
                string,
                Record<string, unknown>
            >;
            const name = id.slice(prefix.length + 1);
            const icon = icons[name];
            if (!icon) this.error(`simple-icons has no ${name}`);
            const { title, slug, hex, path } = icon;

            return `export default ${JSON.stringify({ title, slug, hex, path })};`;
        },
    };
}

function solarIconsPerIcon(options: {
    source: string;
    specifier: string | null;
    importers: RegExp;
    prefix: string;
}): Plugin {
    const { source, specifier, importers, prefix } = options;
    const statements = /import\s*\{([^}]*)\}\s*from\s*(['"])([^'"]+)\2;?/g;
    let file = '';
    let bodies: Map<string, string> | null = null;
    const isSource = (spec: string, importer: string): boolean =>
        specifier !== null
            ? spec === specifier
            : spec.startsWith('.') &&
              resolve(dirname(importer), spec) === file.replace(/\.tsx?$/, '');

    return {
        name: `serveros:solar-icons-per-icon:${source}`,
        apply: 'build',
        enforce: 'pre',
        configResolved(config) {
            file = `${config.root}/${source}`;
        },
        transform(code, id) {
            if (
                id.includes('/node_modules/') ||
                !importers.test(id) ||
                !code.includes('solarIcons')
            ) {
                return null;
            }
            const importer = id.split('?')[0];

            const out = code.replace(
                statements,
                (statement, names: string, _quote: string, spec: string) => {
                    if (!isSource(spec, importer)) return statement;
                    const kept: string[] = [];
                    const lines: string[] = [];
                    for (const name of names.split(',').map((n) => n.trim())) {
                        const [imported, local = imported] =
                            name.split(/\s+as\s+/);
                        if (/^Solar[A-Z0-9]/.test(imported)) {
                            lines.push(
                                `import ${local} from '${prefix}${imported}';`,
                            );
                        } else if (name) {
                            kept.push(name);
                        }
                    }
                    if (kept.length > 0) {
                        lines.unshift(
                            `import { ${kept.join(', ')} } from '${spec}';`,
                        );
                    }
                    const breaks = statement.split('\n').length - 1;

                    return lines.join(' ') + '\n'.repeat(breaks);
                },
            );

            return out === code ? null : { code: out, map: null };
        },
        resolveId(id) {
            return id.startsWith(prefix) ? `\0${id}` : null;
        },
        async load(id) {
            if (!id.startsWith(`\0${prefix}`)) return null;
            if (!bodies) {
                const { readFile } = await import('node:fs/promises');
                const text = await readFile(file, 'utf8');
                bodies = new Map(
                    [
                        ...text.matchAll(
                            /^export const (Solar\w+) = \/\* @__PURE__ \*\/ icon\(\s*'\1',\s*('(?:[^'\\]|\\.)*')/gm,
                        ),
                    ].map((match) => [match[1], match[2]]),
                );
            }
            const name = id.slice(prefix.length + 1);
            const body = bodies.get(name);
            if (!body) this.error(`${source} has no ${name}`);

            return `import { icon } from ${JSON.stringify(file)};\nexport default /* @__PURE__ */ icon('${name}', ${body});`;
        },
    };
}

function panelI18n(): Plugin {
    let file = '';

    return {
        name: 'serveros:panel-i18n',
        enforce: 'pre',
        configResolved(config) {
            file = `${config.root}/resources/js/lib/i18n.ts`;
        },
        resolveId(source, importer) {
            if (source !== 'laravel-vue-i18n' || !importer) return null;
            const from = importer.split('?')[0];

            return from === file ||
                from.includes('/node_modules/') ||
                !from.includes('/resources/js/') ||
                from.includes('/resources/js/marketing/')
                ? null
                : file;
        },
    };
}

const ownEnv = loadEnv('production', process.cwd(), '');
for (const name of existsSync('vendor/serveroshq')
    ? readdirSync('vendor/serveroshq')
    : []) {
    const file = `vendor/serveroshq/${name}/build.env.json`;
    if (!existsSync(file)) {
        continue;
    }
    const defaults = JSON.parse(readFileSync(file, 'utf8')) as Record<
        string,
        string
    >;
    for (const [key, value] of Object.entries(defaults)) {
        if (process.env[key] === undefined && ownEnv[key] === undefined) {
            process.env[key] = value;
        }
    }
}

const sentryEnv = loadEnv('production', process.cwd(), 'SENTRY_');
const sentryOrg = process.env.SENTRY_ORG ?? sentryEnv.SENTRY_ORG;
const sentryProject = process.env.SENTRY_PROJECT ?? sentryEnv.SENTRY_PROJECT;
const sentryUrl =
    process.env.SENTRY_URL ?? sentryEnv.SENTRY_URL ?? 'https://sentry.io/';
const sentryToken =
    sentryOrg && sentryProject
        ? (process.env.SENTRY_AUTH_TOKEN ?? sentryEnv.SENTRY_AUTH_TOKEN)
        : undefined;
const sentryRelease =
    process.env.SENTRY_RELEASE ??
    sentryEnv.SENTRY_RELEASE ??
    /^\d+-([0-9a-f]{7,40})$/.exec(basename(process.cwd()))?.[1];

if (sentryRelease) {
    process.env.VITE_SENTRY_RELEASE ??= sentryRelease;
}

const packages = existsSync('vendor/serveroshq')
    ? readdirSync('vendor/serveroshq').map(
          (name) => `vendor/serveroshq/${name}`,
      )
    : [];

const packageFonts = packages.flatMap((path) => {
    const dir = `${path}/resources/fonts/web`;
    const file = `${dir}/fonts.json`;
    if (!existsSync(file)) {
        return [];
    }
    const families = JSON.parse(readFileSync(file, 'utf8')) as Record<
        string,
        { weights: number[]; preload?: number[]; after?: string }
    >;

    return Object.entries(families).map(
        ([family, { weights, preload, after }]) => ({
            after,
            font: webFont(family, weights, preload, dir),
        }),
    );
});

function withPackageFonts(own: FontDefinition[]): FontDefinition[] {
    const fonts = [...own];
    for (const { after, font } of packageFonts) {
        if (fonts.some(({ family }) => family === font.family)) {
            continue;
        }
        const at = fonts.findIndex(({ family }) => family === after);
        fonts.splice(at < 0 ? fonts.length : at + 1, 0, font);
    }

    return fonts;
}

const linkedPackages = packages
    .filter((path) => lstatSync(path).isSymbolicLink())
    .map((path) => realpathSync(path));

// The linked packages as they appear under vendor/. With preserveSymlinks the
// dev server loads their files by these paths, so the watcher has to see
// changes here or edits to a linked package never reload.
const linkedVendorDirs = packages
    .filter((path) => lstatSync(path).isSymbolicLink())
    .map((path) => resolve(path));

/** Ignores vendor/, except the way in to a linked package and its own files. */
function ignoreVendor(file: string): boolean {
    if (!file.includes('/vendor/')) {
        return false;
    }

    return !linkedVendorDirs.some((dir) => {
        if (dir.startsWith(`${file}/`)) {
            return true;
        }
        if (file !== dir && !file.startsWith(`${dir}/`)) {
            return false;
        }
        const inside = file.slice(dir.length);

        return (
            !inside.includes('/vendor/') && !inside.includes('/node_modules/')
        );
    });
}

const site = packages.find((path) =>
    existsSync(`${path}/resources/js/marketing/app.tsx`),
);
const siteSsr =
    site && existsSync(`${site}/resources/js/marketing/ssr.tsx`)
        ? `${site}/resources/js/marketing/ssr.tsx`
        : undefined;

function packageStyles(): Plugin {
    return {
        name: 'serveros:package-css',
        enforce: 'pre',
        transform(code, id) {
            if (!id.split('?')[0].endsWith('/resources/css/app.css')) {
                return null;
            }
            const out = code.replace(
                /@package-css\s+(['"])([\w.-]+\.css)\1\s*;/g,
                (_rule, _quote: string, file: string) => {
                    const found = packages
                        .map((path) => `${path}/resources/css/${file}`)
                        .find((path) => existsSync(path));

                    return found ? `@import '../../${found}';` : '';
                },
            );

            return out === code ? null : { code: out, map: null };
        },
    };
}

function packageTranslations(): Plugin {
    let langDir = '';

    return {
        name: 'serveros:package-translations',
        enforce: 'pre',
        configResolved(config) {
            langDir = `${config.root}/lang/`;
        },
        load(id) {
            const file = id.split('?')[0];
            const name = file.startsWith(langDir)
                ? file.slice(langDir.length)
                : '';
            if (!/^[a-z]{2,3}\.json$/.test(name)) return null;
            const extra = packages
                .map((path) => `${path}/lang/${name}`)
                .filter((path) => existsSync(path));
            if (extra.length === 0) return null;

            const lines = JSON.parse(readFileSync(file, 'utf8')) as Record<
                string,
                string
            >;
            for (const path of extra) {
                this.addWatchFile(path);
                const own = JSON.parse(readFileSync(path, 'utf8')) as Record<
                    string,
                    string
                >;
                for (const [key, line] of Object.entries(own)) {
                    if (!(key in lines)) lines[key] = line;
                }
            }

            return JSON.stringify(lines);
        },
    };
}

function packagePublicFiles(): Plugin {
    const mark = '# From a private package: vite.config.ts copies these in.';
    const ignoreOf = (dir: string) => join(dir, '.gitignore');
    const ours = (dir: string): string | null => {
        const file = ignoreOf(dir);
        if (!existsSync(file)) return null;
        const text = readFileSync(file, 'utf8');

        return text.startsWith(mark) ? text : null;
    };
    const copy = (from: string, to: string): void => {
        const whole = !existsSync(to) || ours(to) === `${mark}\n*\n`;
        mkdirSync(to, { recursive: true });
        const files: string[] = [];
        for (const entry of readdirSync(from, { withFileTypes: true })) {
            const source = join(from, entry.name);
            const target = join(to, entry.name);
            if (entry.isDirectory()) {
                copy(source, target);
            } else if (entry.isFile() && entry.name !== '.gitignore') {
                if (
                    !existsSync(target) ||
                    statSync(target).size !== statSync(source).size ||
                    statSync(target).mtimeMs < statSync(source).mtimeMs
                ) {
                    copyFileSync(source, target);
                }
                files.push(entry.name);
            }
        }
        if (whole) {
            writeFileSync(ignoreOf(to), `${mark}\n*\n`);
        } else if (
            files.length > 0 &&
            (!existsSync(ignoreOf(to)) || ours(to) !== null)
        ) {
            writeFileSync(
                ignoreOf(to),
                [
                    mark,
                    '/.gitignore',
                    ...files.sort().map((f) => `/${f}`),
                    '',
                ].join('\n'),
            );
        }
    };

    return {
        name: 'serveros:package-public-files',
        buildStart() {
            for (const path of packages) {
                if (existsSync(`${path}/public`)) {
                    copy(`${path}/public`, 'public');
                }
            }
        },
    };
}

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
                ...(site ? [`${site}/resources/js/marketing/app.tsx`] : []),
            ],
            ssr: siteSsr,
            refresh: true,
            fonts: withPackageFonts([
                webFont('Instrument Sans', [400, 500, 600]),
                webFont('Schibsted Grotesk', [400, 500]),
                webFont('Cabin', [400, 500]),
                webFont('Mona Sans', [400, 500, 600], [400, 500]),
                webFont('Geist', [400, 500, 600], [400, 500]),
                webFont('Geist Mono', [400, 500]),
                webFont('Inter', [400, 500, 600]),
            ]),
        }),
        packageStyles(),
        packageTranslations(),
        simpleIconsPerIcon(),
        ...(site
            ? [
                  solarIconsPerIcon({
                      source: `${site}/resources/js/marketing/lib/solarIcons.tsx`,
                      specifier: null,
                      importers: /\.tsx?$/,
                      prefix: 'virtual:solar-icon/',
                  }),
              ]
            : []),
        solarIconsPerIcon({
            source: 'resources/js/lib/solarIcons.ts',
            specifier: '@/lib/solarIcons',
            importers: /\.(?:ts|vue)$/,
            prefix: 'virtual:panel-solar-icon/',
        }),
        panelI18n(),
        inertia({ ssr: siteSsr ?? false }),
        packagePublicFiles(),
        tailwindcss(),
        react({ include: /resources\/js\/marketing\/.*\.tsx$/ }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        {
            name: 'serveros:no-react-refresh-in-vue',
            apply: 'serve',
            configureServer(server) {
                const { oxc } = server.config;

                if (oxc && typeof oxc.jsx === 'object') {
                    (server.config as { oxc: typeof oxc }).oxc = {
                        ...oxc,
                        jsx: { ...oxc.jsx, refresh: false },
                    };
                }
            },
        },
        wayfinder({
            formVariants: true,
        }),
        ...(sentryToken
            ? [
                  sentryVitePlugin({
                      org: sentryOrg,
                      project: sentryProject,
                      url: sentryUrl,
                      authToken: sentryToken,
                      release: sentryRelease
                          ? { name: sentryRelease }
                          : undefined,
                      sourcemaps: {
                          filesToDeleteAfterUpload: ['public/build/**/*.map'],
                      },
                      telemetry: false,
                  }),
              ]
            : []),
        {
            name: 'serveros:self-contained-ssr-bundle',
            apply: 'build',
            config: (_config, env) =>
                env.isSsrBuild ? { ssr: { noExternal: true } } : undefined,
        },
    ]),
    resolve: {
        dedupe: ['react', 'react-dom'],
        ...(linkedPackages.length > 0 ? { preserveSymlinks: true } : {}),
    },
    optimizeDeps: {
        include: ['laravel-react-i18n', 'laravel-vue-i18n'],
    },
    build: {
        sourcemap: sentryToken ? 'hidden' : false,
        rolldownOptions: {
            output: {
                codeSplitting: {
                    groups: [
                        {
                            name: 'icons',
                            test: /virtual:panel-solar-icon|@lucide[\\/]vue[\\/]dist[\\/]esm[\\/]icons/,
                            entriesAware: true,
                            entriesAwareMergeThreshold: 8000,
                        },
                    ],
                },
            },
        },
    },
    server: {
        ...(linkedPackages.length > 0
            ? { fs: { allow: [process.cwd(), ...linkedPackages] } }
            : {}),
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                ignoreVendor,
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'public/**',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
