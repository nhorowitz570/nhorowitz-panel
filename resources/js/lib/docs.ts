export function docsUrl(base: string, path = ''): string {
    return `${base.replace(/\/$/, '')}${path}`;
}
