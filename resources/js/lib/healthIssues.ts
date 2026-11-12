export type HealthIssue = {
    id: string;
    severity: 'critical' | 'warning' | 'notice';
    title: string;
    detail: string;
    href: string;
    action: string;
    since: string | null;
    machineId: number | null;
    ask?: string | null;
};
