import { trans } from 'laravel-vue-i18n';
export type LockedPlan = 'pro' | 'business';
export type PlanKey = 'starter' | LockedPlan;

export const planNames: Record<PlanKey, string> = {
    starter: trans('Essential'),
    pro: trans('Pro'),
    business: trans('Business'),
};

export type Pricing = {
    currency: string;
    symbol: string;
    plans: Partial<
        Record<PlanKey, { monthly: number | null; annual: number | null }>
    >;
    mcpPack: number | null;
};

export function monthlyPrice(
    pricing: Pricing | null | undefined,
    plan: PlanKey,
): string | null {
    const minor = pricing?.plans[plan]?.monthly;
    if (!pricing || minor === null || minor === undefined) return null;

    const amount = minor / 100;

    return (
        pricing.symbol +
        (Number.isInteger(amount) ? String(amount) : amount.toFixed(2))
    );
}

export const planRows: { label: string; values: Record<PlanKey, string> }[] = [
    {
        label: trans('Machines'),
        values: {
            starter: '1',
            pro: trans('Up to 5'),
            business: trans('Unlimited'),
        },
    },
    {
        label: trans('Workflows'),
        values: {
            starter: trans('No'),
            pro: trans('Yes'),
            business: trans('Yes'),
        },
    },
    {
        label: trans('Status pages'),
        values: {
            starter: trans('No'),
            pro: trans('One per machine'),
            business: trans('Per machine, and one for all'),
        },
    },
    {
        label: trans('Logs kept'),
        values: { starter: '3 days', pro: '14 days', business: '30 days' },
    },
    {
        label: trans('Uptime monitors'),
        values: { starter: '10', pro: '50', business: '250' },
    },
    {
        label: trans('Roles'),
        values: {
            starter: trans('Owner, Admin'),
            pro: trans('Staff roles'),
            business: trans('Custom roles'),
        },
    },
    {
        label: trans('Single sign-on'),
        values: {
            starter: trans('No'),
            pro: trans('No'),
            business: trans('SAML and OIDC'),
        },
    },
];
