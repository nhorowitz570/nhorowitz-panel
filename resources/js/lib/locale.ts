import { router } from '@inertiajs/vue3';

export function changeLocale(locale: string): void {
    router.post(
        '/locale',
        { locale },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => window.location.reload(),
        },
    );
}
