const names = new Intl.DisplayNames(['en'], { type: 'region' });

export function countryName(code: string): string {
    if (code === 'T1') return 'Tor';
    try {
        return names.of(code) ?? code;
    } catch {
        return code;
    }
}

export function countryFlagUrl(code: string): string | null {
    return /^[A-Z]{2}$/.test(code)
        ? `https://flagcdn.com/${code.toLowerCase()}.svg`
        : null;
}
