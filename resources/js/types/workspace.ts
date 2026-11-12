export type WorkspaceBilling = {
    plan: PlanKey | null;
    planLabel: string | null;
    interval: BillingInterval | null;
    subscriptionStatus: string | null;
    graceUntil?: string | null;
    hasBillingPortal: boolean;
    onTrial: boolean;
    trialEndsAt: string | null;
    trialDaysLeft: number | null;
    machineCount: number;
    machineLimit: number | null;
    canAddMachine: boolean;
    contract?: WorkspaceContract | null;
    selfHosted?: true;
};

export type WorkspaceContract = {
    key: string;
    label: string;
    machines: number;
    included: number;
    extra: number;
    price: number;
    extraPrice: number;
    interval: BillingInterval;
};

export type Workspace = {
    id: number;
    name: string;
    slug: string;
    host: string;
    logoUrl: string | null;
    billing: WorkspaceBilling;
};

export type WorkspaceRoleKey = 'owner' | 'admin' | 'member' | 'viewer';

export type WorkspacePermission =
    | 'manage-billing'
    | 'manage-settings'
    | 'manage-members'
    | 'manage-machines';

export type WorkspaceAccess = {
    role: WorkspaceRoleKey;
    permissions: WorkspacePermission[];
};

export type WorkspaceRoleOption = {
    value: WorkspaceRoleKey;
    label: string;
    description: string;
    staff: boolean;
    permissions: WorkspacePermission[];
};

export type WorkspaceMember = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    role: WorkspaceRoleKey;
    joinedAt: string | null;
    isYou: boolean;
};

export type WorkspaceInvitation = {
    id: number;
    email: string;
    role: WorkspaceRoleKey;
    invitedBy: string | null;
    expiresAt: string;
    expired: boolean;
};

export type WorkspaceSummary = {
    id: number;
    name: string;
    slug: string;
    host: string;
    url: string;
    switchUrl: string;
    logoUrl: string | null;
    pinnedAt: string | null;
};

export type PlanKey = 'starter' | 'pro' | 'business';

export type BillingInterval = 'monthly' | 'annual';

export type Plan = {
    value: PlanKey;
    label: string;
    summary: string;
    monthlyPrice: number | null;
    annualPrice: number | null;
    machineLimit: number | null;
    mcpCallLimit: number;
    monitorLimit: number;
    minMonitorInterval: number;
    logRetentionDays: number;
    logLinesPerDay: number;
    activityRetentionDays: number;
    features: string[];
};

export type McpCallsUsage = {
    used: number;
    included: number | null;
    extra: number;
    limit: number | null;
    unlimited: boolean;
    resetsAt: string;
    packCalls: number;
    packPrice: number | null;
    currency: string;
    maxPacks: number;
};

export type OnboardingStepKey =
    | 'workspace'
    | 'machine'
    | 'service'
    | 'backups'
    | 'plan';

export type OnboardingStep = {
    key: OnboardingStepKey;
    done: boolean;
};

export type SetupGuide = {
    percent: number;
    groups: {
        key: string;
        title: string;
        locked: string | null;
        steps: {
            key: string;
            title: string;
            body: string;
            href: string;
            done: boolean;
        }[];
    }[];
};

export type WorkspaceStats = {
    machines: number;
    environments: Record<'production' | 'staging' | 'development', number>;
    services: number;
    averageCpu: number;
    busy: number;
    backedUp: number;
    cpuHistory: (number | null)[];
    cpuTimeline: (number | null)[];
    cpuRange: '1h' | '24h' | '7d' | '30d';
};
