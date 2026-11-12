export type ChangelogCategory = 'feature' | 'improvement' | 'fix' | 'security';

export type ChangelogCard = {
    id: number;
    title: string;
    slug: string;
    category: ChangelogCategory;
    categoryLabel: string;
    summary: string;
    imageUrl: string | null;
    linkUrl: string | null;
    linkLabel: string | null;
    publishedAt: string | null;
    url: string;
};

export const changelogTone: Record<ChangelogCategory, string> = {
    feature: 'bg-(--ds-blue)/12 text-(--ds-blue)',
    improvement: 'bg-(--ds-green)/12 text-(--ds-green)',
    fix: 'bg-(--ds-amber)/14 text-(--ds-amber)',
    security: 'bg-(--ds-red)/12 text-(--ds-red)',
};

export function changelogDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
              timeZone: 'Europe/London',
          })
        : '';
}
