<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, Plus, Search } from '@lucide/vue';
import {
    SolarEye as Eye,
    SolarEyeOff as EyeOff,
    SolarLayers as Layers,
} from '@/lib/solarIcons';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
} from 'vue';
import {
    serviceSortOptions,
    statusTabs,
} from '@/components/machines/serviceFilters';
import type { ServiceStat } from '@/components/machines/serviceFormat';
import type {
    ServiceSortKey,
    StatusFilter,
    WorkspaceService,
} from '@/components/machines/serviceFilters';
import {
    passes,
    serviceColumnsWithMachine,
    statusGroup,
} from '@/components/machines/serviceFormat';
import ServiceGroupRow from '@/components/machines/ServiceGroupRow.vue';
import ServiceRow from '@/components/machines/ServiceRow.vue';
import type { ServiceGroup } from '@/components/machines/ServicesTable.vue';
import ConnectMachineButton from '@/components/workspace/ConnectMachineButton.vue';
import type { ServiceTemplate } from '@/components/workspace/DeployServiceDialog.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { appButtonClasses } from '@/lib/appButton';
import { useWhenVisible } from '@/composables/useWhenVisible';
import type { Workspace, WorkspaceAccess } from '@/types';

const loadDeployDialog = () =>
    import('@/components/workspace/DeployServiceDialog.vue');
const DeployServiceDialog = defineAsyncComponent(loadDeployDialog);

type MachineSummary = {
    id: number;
    name: string;
    connection: string;
    scanned: boolean;
};

const props = defineProps<{
    workspace: Workspace;
    services: WorkspaceService[];
    groups: (ServiceGroup & { machineId: number })[];
    machines: MachineSummary[];
    templates: ServiceTemplate[];
    deployEnabled: boolean;
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

function isOnline(machine: MachineSummary): boolean {
    return ['online', 'degraded'].includes(machine.connection);
}

const deployTargets = computed(() =>
    canManage.value && props.deployEnabled
        ? props.machines.filter(isOnline)
        : [],
);

const offline = computed(() =>
    props.machines.filter((m) => m.scanned && !isOnline(m)),
);

const waitingForScan = computed(() => props.machines.some((m) => !m.scanned));

const aboutOpen = ref(false);
const deployOpen = ref(false);
const deployMachine = ref<number | null>(null);

function deployTo(id: number): void {
    deployMachine.value = id;
    deployOpen.value = true;
}

const filter = ref<StatusFilter>('all');
const search = ref('');
const sort = ref<ServiceSortKey>('cpu');
const machineFilter = ref<number | null>(null);
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');

const showHidden = ref(false);
const hiddenCount = computed(
    () => props.services.filter((service) => service.hidden).length,
);
const shown = computed(() =>
    showHidden.value
        ? props.services
        : props.services.filter((service) => !service.hidden),
);

const tabs = computed(() => statusTabs(shown.value));

const sortLabel = computed(
    () => serviceSortOptions.find((o) => o.value === sort.value)?.label,
);

const machineOptions = computed(() =>
    props.machines.filter((m) =>
        props.services.some((s) => s.machine.id === m.id),
    ),
);

const machineLabel = computed(
    () =>
        props.machines.find((m) => m.id === machineFilter.value)?.name ??
        trans('All'),
);

function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const typing =
        target?.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '');

    if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
        event.preventDefault();
        searchInput.value?.focus();
    }
}

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();
    const now = Date.now() / 1000;
    const value = (service: WorkspaceService): number => {
        switch (sort.value) {
            case 'cpu':
                return service.stat?.cpu ?? -1;
            case 'memory':
                return service.stat?.memUsed ?? -1;
            case 'uptime':
                return service.stat?.startedAt
                    ? now - service.stat.startedAt
                    : -1;
            default:
                return 0;
        }
    };

    return shown.value
        .filter((s) => passes(s, filter.value))
        .filter(
            (s) =>
                machineFilter.value === null ||
                s.machine.id === machineFilter.value,
        )
        .filter(
            (s) =>
                !needle ||
                `${s.name} ${s.manager} ${s.kind} ${s.ports.join(' ')} ${s.machine.name}`
                    .toLowerCase()
                    .includes(needle),
        )
        .sort((a, b) =>
            sort.value === 'name'
                ? a.name.localeCompare(b.name)
                : value(b) - value(a),
        );
});

type Row =
    | { id: string; service: WorkspaceService }
    | {
          id: string;
          kind: 'group' | 'stack';
          name: string;
          iconUrl: string | null;
          machine: WorkspaceService['machine'];
          services: WorkspaceService[];
      };

// Same grouping as a machine's page: saved groups first, then Compose
// stacks of two or more. A group sits where its top service would.
const rows = computed<Row[]>(() => {
    const owner = new Map<WorkspaceService, string>();
    const groups = new Map<string, Extract<Row, { services: unknown }>>();

    for (const group of props.groups) {
        const id = `${group.machineId}:g:${group.id}`;
        const inside = visible.value.filter(
            (s) =>
                s.machine.id === group.machineId &&
                !owner.has(s) &&
                (group.services.includes(s.key) ||
                    (!!s.app && group.services.includes(`app:${s.app}`))),
        );
        inside.forEach((s) => owner.set(s, id));
        if (inside.length) {
            groups.set(id, {
                id,
                kind: 'group',
                name: group.name,
                iconUrl: group.iconUrl ?? null,
                machine: inside[0].machine,
                services: inside,
            });
        }
    }

    const stacks = new Map<string, WorkspaceService[]>();
    for (const s of visible.value) {
        const project = s.details?.compose_project;
        if (!project || owner.has(s)) continue;
        const id = `${s.machine.id}:s:${project}`;
        stacks.set(id, [...(stacks.get(id) ?? []), s]);
    }
    for (const [id, inside] of stacks) {
        if (inside.length < 2) continue;
        inside.forEach((s) => owner.set(s, id));
        groups.set(id, {
            id,
            kind: 'stack',
            name: String(inside[0].details?.compose_project).replace(
                /^serveros-/,
                '',
            ),
            iconUrl: null,
            machine: inside[0].machine,
            services: inside,
        });
    }

    const result: Row[] = [];
    const placed = new Set<string>();
    for (const service of visible.value) {
        const id = owner.get(service);
        if (id === undefined) {
            result.push({
                id: `${service.machine.id}:${service.key}`,
                service,
            });
        } else if (!placed.has(id)) {
            placed.add(id);
            result.push(groups.get(id)!);
        }
    }

    return result;
});

const open = ref(new Set<string>());

function isOpen(id: string): boolean {
    return open.value.has(id) || search.value.trim() !== '';
}

function toggle(id: string): void {
    const next = new Set(open.value);
    if (!next.delete(id)) next.add(id);
    open.value = next;
}

function statsOf(services: WorkspaceService[]): Record<string, ServiceStat> {
    return Object.fromEntries(
        services.flatMap((s) => (s.stat ? [[s.key, s.stat]] : [])),
    );
}

function refresh(): void {
    router.reload({ only: ['services', 'groups', 'machines'] });
}

let timer: ReturnType<typeof setTimeout> | undefined;
const whenVisible = useWhenVisible();

function poll(): void {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            whenVisible(() =>
                router.reload({
                    only: ['services', 'groups', 'machines'],
                    onFinish: poll,
                }),
            ),
        waitingForScan.value ? 5000 : 15000,
    );
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    poll();
    void loadDeployDialog();
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimeout(timer);
});

const primaryButton = appButtonClasses('primary', 'md');

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Services')" />

    <WorkspacePage :title="$t('Services')" toolbar>
        <template #description>
            {{
                ' ' +
                $t(
                    'Everything running on your machines, from apps to databases, with what each one is using right now.',
                )
            }}
            <button
                type="button"
                class="cursor-pointer font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                :class="focusRing"
                @click="aboutOpen = true"
            >
                {{ ' ' + $t('Learn more') + ' ' }}
            </button>
        </template>

        <template v-if="services.length > 0" #tabs>
            <div class="max-w-full overflow-x-auto">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Filter by status')"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :class="focusRing"
                        :aria-pressed="filter === tab.value"
                        @click="filter = tab.value"
                    >
                        {{ tab.label }}
                        <span
                            class="text-xs/4 tabular-nums"
                            :class="
                                filter === tab.value
                                    ? 'text-(--ds-text-muted)'
                                    : 'text-(--ds-text-faint)'
                            "
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </div>
            </div>
        </template>

        <template v-if="machines.length > 0" #actions>
            <template v-if="services.length > 0">
                <label
                    class="ds-raised-button w-full cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring) sm:w-72"
                >
                    <Search
                        class="size-3.5 shrink-0 text-(--ds-text-muted)"
                        :stroke-width="1.6"
                    />
                    <span class="sr-only">{{ $t('Search services') }}</span>
                    <input
                        ref="searchInput"
                        v-model="search"
                        type="search"
                        :placeholder="$t('Search services')"
                        class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                    />
                    <kbd
                        class="rounded-[5px] border border-(--ds-border-hover) px-1.25 py-px font-ui text-[11px]/3.5 text-(--ds-text-muted)"
                        aria-hidden="true"
                    >
                        /
                    </kbd>
                </label>

                <DropdownMenu v-if="machineOptions.length > 1">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="ds-raised-button cursor-pointer text-[13px]!"
                            :class="focusRing"
                        >
                            <span class="text-(--ds-text-muted)">{{
                                $t('Machine')
                            }}</span>
                            <span class="max-w-32 truncate font-medium">
                                {{ machineLabel }}
                            </span>
                            <ChevronDown
                                class="size-2.5 text-(--ds-text-muted)"
                                :stroke-width="2"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-40 font-ui"
                        align="end"
                    >
                        <DropdownMenuItem @select="machineFilter = null">
                            {{ ' ' + $t('All') }}
                            <Check
                                v-if="machineFilter === null"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-for="option in machineOptions"
                            :key="option.id"
                            @select="machineFilter = option.id"
                        >
                            {{ option.name }}
                            <Check
                                v-if="machineFilter === option.id"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <button
                    v-if="hiddenCount > 0"
                    type="button"
                    class="ds-raised-button cursor-pointer text-[13px]!"
                    :class="focusRing"
                    :aria-pressed="showHidden"
                    @click="showHidden = !showHidden"
                >
                    <component
                        :is="showHidden ? EyeOff : Eye"
                        class="size-3.5 text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                    {{
                        showHidden ? $t('Leave out hidden') : $t('Show hidden')
                    }}
                    <span class="text-(--ds-text-muted) tabular-nums">{{
                        hiddenCount
                    }}</span>
                </button>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="ds-raised-button cursor-pointer text-[13px]!"
                            :class="focusRing"
                        >
                            <span class="text-(--ds-text-muted)">{{
                                $t('Sort')
                            }}</span>
                            <span class="font-medium">{{ sortLabel }}</span>
                            <ChevronDown
                                class="size-2.5 text-(--ds-text-muted)"
                                :stroke-width="2"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-40 font-ui"
                        align="end"
                    >
                        <DropdownMenuItem
                            v-for="option in serviceSortOptions"
                            :key="option.value"
                            @select="sort = option.value"
                        >
                            {{ option.label }}
                            <Check
                                v-if="sort === option.value"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>

            <button
                v-if="deployTargets.length <= 1"
                type="button"
                :class="primaryButton"
                :disabled="deployTargets.length === 0"
                :title="
                    deployTargets.length === 0
                        ? $t('Deploying needs a machine that is online')
                        : undefined
                "
                @click="deployTargets[0] && deployTo(deployTargets[0].id)"
            >
                <Plus class="mi-plus size-3.5" :stroke-width="2" />
                {{ $t('Deploy service') + ' ' }}
            </button>
            <DropdownMenu v-else>
                <DropdownMenuTrigger as-child>
                    <button type="button" :class="primaryButton">
                        <Plus class="mi-plus size-3.5" :stroke-width="2" />
                        {{ $t('Deploy service') }}
                        <ChevronDown
                            class="-mr-0.5 size-3 opacity-70"
                            :stroke-width="2"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-48 font-ui"
                    align="end"
                >
                    <DropdownMenuLabel
                        class="text-xs font-normal text-(--ds-text-muted)"
                    >
                        {{ ' ' + $t('Deploy to') + ' ' }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="machine in deployTargets"
                        :key="machine.id"
                        @select="deployTo(machine.id)"
                    >
                        <span class="truncate">{{ machine.name }}</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>

        <div
            v-if="machines.length === 0"
            class="ds-tile flex flex-col items-center px-6 py-20 text-center"
        >
            <div class="ds-icon-chip" aria-hidden="true">
                <Layers class="size-4.5" :stroke-width="2" />
            </div>
            <h2 class="mt-5 text-base/6 font-semibold">
                {{ ' ' + $t('No services yet') + ' ' }}
            </h2>
            <p
                class="mt-1.5 max-w-sm text-[13px]/5 text-pretty text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t(
                        'Connect a machine and everything already running on it shows up here: apps, sites, databases, workers.',
                    ) +
                    ' '
                }}
            </p>
            <ConnectMachineButton class="mt-6" />
        </div>

        <div v-else class="flex flex-col gap-5">
            <div
                v-if="offline.length"
                class="rounded-2xl bg-(--ds-amber)/8 px-5 py-3.5 text-sm text-amber-800 ring-1 ring-(--ds-amber)/25 dark:text-amber-200"
            >
                {{
                    $t(
                        ':valueValue :value2 offline, so :value3 services show the last scan.',
                        {
                            valueValue: offline.map((m) => m.name).join(', '),
                            value2: offline.length === 1 ? $t('is') : $t('are'),
                            value3:
                                offline.length === 1 ? $t('its') : $t('their'),
                        },
                    ) + ' '
                }}
            </div>

            <div
                v-if="services.length === 0"
                class="ds-tile flex flex-col items-center px-6 py-20 text-center"
            >
                <div class="ds-icon-chip" aria-hidden="true">
                    <Layers class="size-4.5" :stroke-width="2" />
                </div>
                <h2 class="mt-5 text-base/6 font-semibold">
                    {{
                        waitingForScan
                            ? $t('Looking for services')
                            : $t('Nothing running yet')
                    }}
                </h2>
                <p
                    class="mt-1.5 max-w-sm text-[13px]/5 text-pretty text-(--ds-text-muted)"
                >
                    {{
                        waitingForScan
                            ? $t(
                                  'The daemon is scanning for what already runs. Services appear here as soon as it reports back.',
                              )
                            : $t(
                                  'Deploy an app, a site or a database from the library, and it shows up here.',
                              )
                    }}
                </p>
                <button
                    v-if="!waitingForScan && deployTargets.length"
                    type="button"
                    :class="primaryButton"
                    class="mt-6"
                    @click="deployTo(deployTargets[0].id)"
                >
                    <Plus class="size-3.5" /> {{ $t('Deploy service') + ' ' }}
                </button>
            </div>

            <div
                v-else
                class="ds-tile overflow-x-auto"
                role="table"
                :aria-label="$t('Services')"
            >
                <div
                    class="ds-tile-head hidden h-9.5 border-b border-(--ds-divider) px-4 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                    :class="serviceColumnsWithMachine"
                    role="row"
                >
                    <span role="columnheader">{{ $t('Service') }}</span>
                    <span role="columnheader">{{ $t('Machine') }}</span>
                    <span role="columnheader">{{ $t('Status') }}</span>
                    <span role="columnheader">{{ $t('CPU') }}</span>
                    <span role="columnheader">{{ $t('Memory') }}</span>
                    <span role="columnheader">{{ $t('Uptime') }}</span>
                    <span role="columnheader">{{ $t('Ports') }}</span>
                    <span role="columnheader"
                        ><span class="sr-only">{{ $t('Details') }}</span></span
                    >
                </div>

                <div
                    v-for="row in rows"
                    :key="row.id"
                    class="border-b border-(--ds-divider) last:border-b-0"
                    role="rowgroup"
                >
                    <ServiceRow
                        v-if="'service' in row"
                        :service="row.service"
                        :stat="row.service.stat"
                        :machine-id="row.service.machine.id"
                        :machine-name="row.service.machine.name"
                    />
                    <template v-else>
                        <ServiceGroupRow
                            :name="row.name"
                            :kind="row.kind"
                            :services="row.services"
                            :stats="statsOf(row.services)"
                            :expanded="isOpen(row.id)"
                            :can-manage="false"
                            :icon-url="row.iconUrl"
                            :machine-name="row.machine.name"
                            @toggle="toggle(row.id)"
                        />
                        <div
                            v-if="isOpen(row.id)"
                            :id="`group-${row.kind}-${row.name.replace(/\W+/g, '-')}`"
                            class="border-t border-(--ds-divider) bg-(--ds-subtle)/40"
                        >
                            <ServiceRow
                                v-for="service in row.services"
                                :key="service.key"
                                :service="service"
                                :stat="service.stat"
                                :machine-id="service.machine.id"
                                :machine-name="service.machine.name"
                                nested
                                class="border-b border-(--ds-divider) last:border-b-0"
                            />
                        </div>
                    </template>
                </div>

                <p
                    v-if="visible.length === 0"
                    class="px-4 py-8 text-center text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{ $t('No services match these filters.') }}
                </p>
            </div>
        </div>

        <Dialog v-model:open="aboutOpen">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{ $t('About services') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'A service is anything running on a machine: an app, a site, a database, a worker, a cache.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <ul
                    class="flex list-disc flex-col gap-2.5 pl-4 text-[13px]/5 text-(--ds-text-secondary) marker:text-(--ds-text-faint)"
                >
                    <li>
                        <span class="font-medium text-(--ds-text)">{{
                            $t('Found for you.')
                        }}</span>
                        {{
                            $t(
                                'The daemon scans each machine for containers, systemd units and processes, every hour and after every deploy. Nothing you already run needs moving.',
                            ) + ' '
                        }}
                    </li>
                    <li>
                        <span class="font-medium text-(--ds-text)">{{
                            $t('Adopt to control.')
                        }}</span>
                        {{
                            $t(
                                'Services you deploy here are managed from the start. Adopt one you set up yourself to start, stop, restart, read its logs and open a console.',
                            ) + ' '
                        }}
                    </li>
                    <li>
                        <span class="font-medium text-(--ds-text)">{{
                            $t('Deploy from the library.')
                        }}</span>
                        {{
                            $t(
                                'Pick an app, a site or a database, choose a machine, and it shows up here once it starts.',
                            ) + ' '
                        }}
                    </li>
                    <li>
                        <span class="font-medium text-(--ds-text)">{{
                            $t('Group them on a machine.')
                        }}</span>
                        {{
                            $t(
                                "On a machine's page, put services in groups. Compose stacks group themselves.",
                            )
                        }}
                    </li>
                </ul>
            </DialogContent>
        </Dialog>

        <DeployServiceDialog
            v-if="deployMachine !== null"
            v-model:open="deployOpen"
            :templates="templates"
            :machine-id="deployMachine"
            @deployed="refresh"
        />
    </WorkspacePage>
</template>
