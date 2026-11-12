import { trans } from 'laravel-vue-i18n';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { ComputedRef } from 'vue';
import type { Workspace } from '@/types';

export type SubscriptionNotice = {
    text: string;
    action: string;
    choose: string | null;
    tone: 'calm' | 'urgent';
    dismissible: boolean;
    promo: { code: string; offer: string } | null;
    href?: string;
    dismiss?: () => void;
};

const QUIET_UNTIL_DAYS = 7;

const URGENT_FROM_DAYS = 3;

const dismissedOn = ref<Record<number, string>>({});

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function storageKey(workspaceId: number): string {
    return `subscription-notice-dismissed:${workspaceId}`;
}

function readDismissal(workspaceId: number): string | null {
    if (workspaceId in dismissedOn.value) return dismissedOn.value[workspaceId];
    try {
        return localStorage.getItem(storageKey(workspaceId));
    } catch {
        return null;
    }
}

const creditHidden = ref<Record<number, boolean>>({});

function creditKey(workspaceId: number): string {
    return `cloud-credit-hidden:${workspaceId}`;
}

function isCreditHidden(workspaceId: number): boolean {
    if (workspaceId in creditHidden.value)
        return creditHidden.value[workspaceId];
    try {
        return localStorage.getItem(creditKey(workspaceId)) !== null;
    } catch {
        return false;
    }
}

function hideCredit(workspaceId: number): void {
    creditHidden.value = { ...creditHidden.value, [workspaceId]: true };
    try {
        localStorage.setItem(creditKey(workspaceId), today());
    } catch {}
}

export function dismissSubscriptionNotice(workspaceId: number): void {
    dismissedOn.value = { ...dismissedOn.value, [workspaceId]: today() };
    try {
        localStorage.setItem(storageKey(workspaceId), today());
    } catch {}
}

export function useSubscriptionNotice(): ComputedRef<SubscriptionNotice | null> {
    const page = usePage<{ workspace: Workspace }>();
    const promo = computed(() => page.props.promo ?? null);

    return computed(() => {
        if (page.component === 'workspace/Billing') {
            return null;
        }

        const workspace = page.props.workspace;
        const billing = workspace.billing;

        if (billing.selfHosted) {
            return null;
        }

        if (billing.graceUntil) {
            const until = new Date(billing.graceUntil).toLocaleDateString(
                undefined,
                { day: 'numeric', month: 'long' },
            );

            return {
                text: trans(
                    "Your last payment didn't go through. Update your card by :until to keep monitors, alerts and workflows running.",
                    { until: String(until) },
                ),
                action: trans('Update payment'),
                choose: null,
                tone: 'urgent',
                dismissible: false,
                promo: null,
            };
        }

        if (billing.onTrial) {
            const days = billing.trialDaysLeft ?? 0;
            const plan = billing.planLabel ?? 'Pro';

            if (days > QUIET_UNTIL_DAYS) return null;

            if (days > URGENT_FROM_DAYS) {
                if (readDismissal(workspace.id) === today()) return null;

                return {
                    text: trans('Your :plan trial ends in :count days.', {
                        count: String(days),
                        plan,
                    }),
                    action: trans('Choose a plan'),
                    choose: null,
                    tone: 'calm',
                    dismissible: true,
                    promo: null,
                };
            }

            return {
                text: trans(
                    days === 1
                        ? ':plan ends tomorrow. Choose a plan to keep your monitors and alerts running.'
                        : ':plan ends in :count days. Choose a plan to keep your monitors and alerts running.',
                    { count: String(days), plan },
                ),
                action:
                    days === 1
                        ? trans('Keep :plan', { plan })
                        : trans('Choose a plan'),
                choose: days === 1 ? billing.plan : null,
                tone: 'urgent',
                dismissible: false,
                promo: promo.value,
            };
        }

        if (billing.plan === null) {
            return {
                text: trans("You don't have an active subscription."),
                action: trans('Subscribe'),
                choose: null,
                tone: 'urgent',
                dismissible: false,
                promo: promo.value,
            };
        }

        const credit = page.props.cloudCredit;
        if (
            credit &&
            page.component !== 'workspace/Cloud' &&
            !isCreditHidden(workspace.id)
        ) {
            return {
                text: trans(
                    'Your plan comes with :amount off your first Cloud server.',
                    { amount: credit },
                ),
                action: trans('Get :amount off', { amount: credit }),
                choose: null,
                tone: 'urgent',
                dismissible: true,
                promo: null,
                href: '/cloud?new',
                dismiss: () => hideCredit(workspace.id),
            };
        }

        return null;
    });
}
