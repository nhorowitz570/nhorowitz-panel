import { trans } from 'laravel-vue-i18n';
export type Environment = 'production' | 'staging' | 'development';

export type Machine = {
    id?: number;
    name: string;
    ip: string;
    environment: Environment;
    provider: string;
    providerManual?: boolean;
    region?: string | null;
    regionManual?: boolean;
    tier?: string | null;
    labels?: Record<string, string>;
    services: number;
    memory: string;
    memoryMb?: number | null;
    cpu: number;
    groupId?: number | null;
    lastBackup: string;
    installing?: boolean;
    setup?: 'connecting' | 'discovering' | 'securing' | null;
    setupFindings?: number | null;
    uid?: string | null;
    iconUrl?: string | null;
    connection?: 'demo' | 'connecting' | 'online' | 'degraded' | 'offline';
    daemonVersion?: string | null;
    lastSeen?: string | null;
    disks?: { mount: string; used: number; total: number }[];
    diskPercent?: number | null;
    maintenance?: {
        until: string;
        reason: string | null;
        public: boolean;
    } | null;
    plannedMaintenance?: PlannedMaintenance[];
    facts?: {
        os?: string;
        os_version?: string;
        kernel?: string;
        arch?: string;
        cpu_model?: string;
        cpu_cores?: number;
        memory_bytes?: number;
        disks?: {
            mount: string;
            device: string;
            fs_type: string;
            total_bytes: number;
        }[];
        interfaces?: {
            name: string;
            addresses: string[];
            mac?: string | null;
        }[];
        hosting?: {
            provider?: string | null;
            region?: string | null;
            zone?: string | null;
            instance_type?: string | null;
            source?: string | null;
        };
    } | null;
    inventory?: {
        scanned_at: number;
        complete: boolean;
        services: {
            key: string;
            name: string;
            app?: string | null;
            label?: string | null;
            found?: string | null;
            icon?: string | null;
            hidden?: boolean;
            logsMuted?: boolean;
            kind: string;
            manager: string;
            status: string;
            ports: number[];
            confidence: number;
            version?: string | null;
            working_dir?: string | null;
            user?: string | null;
            exec?: string | null;
            config_paths?: string[];
            data_dir?: string | null;
            capabilities?: string[];
            origin?: 'discovered' | 'created';
            details?: Record<string, string>;
        }[];
        listeners: {
            proto: string;
            address: string;
            port: number;
            pid: number | null;
            exe: string | null;
        }[];
        unknown: { port: number; exe: string | null; note: string }[];
        warnings: string[];
    } | null;
    firewall?: FirewallView | null;
};

export type FirewallExposure =
    | 'open'
    | 'restricted'
    | 'blocked'
    | 'docker'
    | 'unknown';

export type FirewallView = {
    supported: boolean;
    backend: string | null;
    installed: boolean;
    active: boolean;
    defaultIncoming: 'allow' | 'limit' | 'deny' | 'reject' | null;
    unavailable: string | null;
    ports: {
        port: number;
        proto: string;
        process: string | null;
        exposure: FirewallExposure | null;
        because: string | null;
        what: string | null;
        ssh: boolean;
    }[];
    rules: {
        verdict: 'allow' | 'limit' | 'deny' | 'reject';
        ports: string;
        proto: string | null;
        source: string | null;
        ipv6: boolean;
        managed: boolean;
        opaque: boolean;
        text: string;
    }[];
    unreadable: number;
    notes: string[];
    presets: { label: string; port: number; proto: string }[];
};

export const environmentLabels: Record<Environment, string> = {
    production: trans('Production'),
    staging: trans('Staging'),
    development: trans('Development'),
};

export type MachineColumnKey =
    | 'machine'
    | 'environment'
    | 'provider'
    | 'services'
    | 'memory'
    | 'cpu'
    | 'lastBackup'
    | 'actions';

export type MachineColumns = Record<MachineColumnKey, string>;

export const columnLabels: Record<
    Exclude<MachineColumnKey, 'actions'>,
    string
> = {
    machine: trans('Machine'),
    environment: trans('Environment'),
    provider: trans('Provider'),
    services: trans('Services'),
    memory: trans('Memory'),
    cpu: 'CPU',
    lastBackup: trans('Last backup'),
};

export const tableColumns: MachineColumns = {
    machine: 'min-w-62.5 flex-1',
    environment: 'w-40 shrink-0',
    provider: 'w-35 shrink-0',
    services: 'w-22.5 shrink-0',
    memory: 'w-25 shrink-0',
    cpu: 'w-47.5 shrink-0',
    lastBackup: 'w-37.5 shrink-0',
    actions: 'w-15.5 shrink-0',
};

export type PlannedMaintenance = {
    id: number;
    startsAt: string;
    endsAt: string;
    reason: string | null;
    state: 'scheduled' | 'active' | 'done' | 'cancelled';
    action: 'none' | 'reboot' | 'updates';
    public: boolean;
    phase: 'updating' | 'rebooting' | 'back' | null;
    outcome: string | null;
    by: string | null;
};
