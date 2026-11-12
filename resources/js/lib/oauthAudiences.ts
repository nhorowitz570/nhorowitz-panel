export type OAuthAudienceCopy = {
    name: string;
    where: string;
    notice: string;
    read: string;
    write: string;
};

const fromPackages = import.meta.glob<{ default: OAuthAudienceCopy }>(
    '../../../vendor/serveroshq/*/resources/js/oauth/*.ts',
    { eager: true },
);

const AUDIENCE_FILE = /\/resources\/js\/oauth\/([^/]+)\.ts$/;

export function oauthAudienceFor(
    props: Record<string, unknown>,
): OAuthAudienceCopy | null {
    for (const [path, module] of Object.entries(fromPackages)) {
        const key = AUDIENCE_FILE.exec(path)?.[1];

        if (key && props[key] === true) {
            return module.default;
        }
    }

    return null;
}
