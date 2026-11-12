import { I18n, i18nVue } from 'laravel-vue-i18n';
import type { App } from 'vue';

const files = import.meta.glob<{ default: Record<string, string> }>(
    '../../../lang/*.json',
);

function pageLocale(): string {
    return typeof document === 'undefined'
        ? 'en'
        : document.documentElement.lang.split('-')[0] || 'en';
}

const locale = pageLocale();

const lines: Record<string, string> =
    locale === 'en'
        ? {}
        : ((
              await files[`../../../lang/${locale}.json`]?.().catch(
                  () => undefined,
              )
          )?.default ?? {});

const resolve = (lang: string) =>
    lang === locale ? lines : (files[`../../../lang/${lang}.json`]?.() ?? {});

I18n.getSharedInstance({
    lang: locale,
    fallbackLang: 'en',
    resolve,
}).loadLanguage(locale);

export * from 'laravel-vue-i18n';

export function installI18n(app: App, locale: string): void {
    app.use(i18nVue, { lang: locale, fallbackLang: 'en', resolve });
}
