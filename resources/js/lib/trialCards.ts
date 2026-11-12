export type TrialCardsCopy = {
    checking: string;
    pending: string;
};

const fromPackages = import.meta.glob<{ default: TrialCardsCopy }>(
    '../../../vendor/serveroshq/*/resources/js/trialCards.ts',
    { eager: true },
);

export const trialCardsCopy: TrialCardsCopy = Object.values(fromPackages)[0]
    ?.default ?? {
    checking: 'Checking your card…',
    pending: 'Your card is still being confirmed. Refresh in a moment.',
};
