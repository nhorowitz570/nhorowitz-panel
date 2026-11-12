import { ref } from 'vue';

export type ToastType =
    | 'default'
    | 'success'
    | 'error'
    | 'warning'
    | 'info'
    | 'loading';

export type ToastAction = { label: string; onClick: () => void };

export type ToastOptions = {
    id?: string | number;
    description?: string;
    duration?: number;
    action?: ToastAction;
};

export type Toast = {
    id: string | number;
    type: ToastType;
    title: string;
    description?: string;
    duration: number;
    action?: ToastAction;
    version: number;
};

const defaults: Record<ToastType, number> = {
    default: 4000,
    success: 4000,
    info: 4000,
    warning: 6000,
    error: 6000,
    loading: Infinity,
};

export const currentToast = ref<Toast | null>(null);

let counter = 0;

function show(
    type: ToastType,
    title: string,
    options: ToastOptions = {},
): string | number {
    const id = options.id ?? ++counter;
    const duration =
        options.duration === 0
            ? Infinity
            : (options.duration ?? defaults[type]);

    currentToast.value = {
        id,
        type,
        title,
        description: options.description,
        duration,
        action: options.action,
        version: (currentToast.value?.version ?? 0) + 1,
    };

    return id;
}

function dismiss(id?: string | number): void {
    if (id === undefined || currentToast.value?.id === id) {
        currentToast.value = null;
    }
}

export const toast = Object.assign(
    (title: string, options?: ToastOptions) => show('default', title, options),
    {
        success: (title: string, options?: ToastOptions) =>
            show('success', title, options),
        error: (title: string, options?: ToastOptions) =>
            show('error', title, options),
        warning: (title: string, options?: ToastOptions) =>
            show('warning', title, options),
        info: (title: string, options?: ToastOptions) =>
            show('info', title, options),
        loading: (title: string, options?: ToastOptions) =>
            show('loading', title, options),
        dismiss,
    },
);
