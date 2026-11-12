<script setup lang="ts">
// Lit-ridge dot field after caffeine-day by Charles Postiaux (MIT).
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { useIsDark } from '@/composables/useAppearance';

type Props = {
    values: (number | null)[];
    labels: string[];
    ticks: { at: number; label: string }[];
    threshold?: { value: number; label: string; note: string } | null;
    unit?: string;
    hot?: number;
    label: string;
};

const {
    values,
    labels,
    ticks,
    threshold = null,
    unit = '%',
    hot = 3,
    label,
} = defineProps<Props>();

const stage = useTemplateRef<HTMLDivElement>('stage');
const canvas = useTemplateRef<HTMLCanvasElement>('canvas');
const isDark = useIsDark();
const hover = ref<number | null>(null);

const PITCH = 6;
const LEVELS = 14;
const AXIS = 26;
const TOP = 34;
const RIGHT = 0;

let width = 0;
let height = 0;
let grow = 1;
let raf = 0;
let observer: ResizeObserver | undefined;

function clamp(v: number, lo: number, hi: number): number {
    return Math.min(hi, Math.max(lo, v));
}

function sample(position: number): number | null {
    const at = position * (values.length - 1);
    const i = Math.floor(at);
    const a = values[i];
    const b = values[Math.min(values.length - 1, i + 1)];
    const t = at - i;

    if (a === null || a === undefined) {
        return t > 0.5 && b !== null && b !== undefined ? b : null;
    }
    if (b === null || b === undefined) {
        return t < 0.5 ? a : null;
    }

    return a + (b - a) * (t * t * (3 - 2 * t));
}

const empty = computed(() => values.every((value) => value === null));

function palette() {
    const dark = isDark.value;

    return {
        greys: Array.from({ length: LEVELS }, (_, i) => {
            const k = (i / (LEVELS - 1)) ** 1.05;
            const g = dark
                ? Math.round(44 + 194 * k)
                : Math.round(222 - 196 * k);

            return `rgb(${g},${g},${g})`;
        }),
        near: dark ? '#242424' : '#e4e4e2',
        far: dark ? '#171717' : '#efefed',
        line: dark ? '#3a3a3a' : '#c9c9c4',
        dim: dark ? '#5e5e5e' : '#8e8e88',
        ink: dark ? '#d4d4d4' : '#3f3f3c',
        accent: '#3b7bff',
    };
}

function font(): string {
    const family =
        getComputedStyle(document.documentElement)
            .getPropertyValue('--font-ui-mono')
            .trim() || 'ui-monospace, monospace';

    return `400 10px ${family}`;
}

function draw(): void {
    const el = canvas.value;
    const ctx = el?.getContext('2d');

    if (!el || !ctx || width === 0 || height === 0) {
        return;
    }

    const dpr = window.devicePixelRatio || 1;
    el.width = Math.round(width * dpr);
    el.height = Math.round(height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);

    const c = palette();
    ctx.font = font();

    const fx = 0;
    const fw = Math.max(40, width - RIGHT);
    const fh = Math.max(40, height - AXIS - TOP);
    const base = TOP + fh;
    const cols = Math.max(8, Math.floor(fw / PITCH));
    const rows = Math.max(6, Math.floor(fh / PITCH));
    const cw = fw / cols;
    const rh = fh / rows;

    const known = values.filter((v): v is number => v !== null);
    const peak = known.length ? Math.max(...known) : 0;
    const yMax = Math.max(10, peak * 1.6);
    const series = Array.from({ length: cols }, (_, i) => {
        const value = sample((i + 0.5) / cols);

        return value === null ? null : value * grow;
    });
    const hotFrom = cols - Math.round((hot / values.length) * cols);

    const buckets: number[][] = Array.from({ length: LEVELS }, () => []);
    const blues: number[] = [];
    const near: number[] = [];
    const far: number[] = [];

    for (let col = 0; col < cols; col++) {
        const x = fx + (col + 0.5) * cw;
        const here = series[col];
        const ridge = here === null ? -1 : (here / yMax) * rows;
        const slope =
            (((series[Math.min(cols - 1, col + 1)] ?? here ?? 0) -
                (series[Math.max(0, col - 1)] ?? here ?? 0)) /
                yMax) *
            rows;
        const light = clamp(0.62 + slope * 1.1, 0.22, 1);

        for (let r = 0; r < rows; r++) {
            const y = base - (r + 0.5) * rh;

            if (r + 0.5 > ridge + 0.5) {
                if (r < ridge + 5) near.push(x, y);
                else if ((r + col) % 2 === 0) far.push(x, y);
                continue;
            }

            const depth = clamp((r + 0.5) / Math.max(ridge, 1), 0, 1);
            const edge = clamp(ridge - r + 0.5, 0, 1);
            const val = clamp(
                depth ** 1.5 *
                    light *
                    edge *
                    (0.45 + 0.55 * Math.sqrt(clamp(ridge / rows, 0, 1))),
                0,
                1,
            );

            if (val < 0.015) continue;

            const rad = PITCH * (0.1 + 0.36 * Math.sqrt(val));

            if (col >= hotFrom) blues.push(x, y, rad * (0.8 + 0.3 * val));
            else
                buckets[
                    Math.min(LEVELS - 1, Math.floor(val * LEVELS * 1.05))
                ].push(x, y, rad);
        }
    }

    const dots = (list: number[]) => {
        ctx.beginPath();
        for (let n = 0; n < list.length; n += 3) {
            const r = list[n + 2];
            ctx.moveTo(list[n] + r, list[n + 1]);
            ctx.arc(list[n], list[n + 1], r, 0, Math.PI * 2);
        }
        ctx.fill();
    };
    const pixels = (list: number[], color: string) => {
        ctx.fillStyle = color;
        ctx.beginPath();
        for (let n = 0; n < list.length; n += 2) {
            ctx.rect(list[n] - 0.5, list[n + 1] - 0.5, 1, 1);
        }
        ctx.fill();
    };

    pixels(far, c.far);
    pixels(near, c.near);
    buckets.forEach((list, level) => {
        if (list.length) {
            ctx.fillStyle = c.greys[level];
            dots(list);
        }
    });
    ctx.fillStyle = c.accent;
    dots(blues);

    const text = (
        str: string,
        x: number,
        y: number,
        color: string,
        align: CanvasTextAlign = 'left',
    ) => {
        ctx.textAlign = align;
        ctx.fillStyle = color;
        ctx.fillText(str.toUpperCase(), x, y);
    };

    if (threshold && known.length > 0 && threshold.value <= yMax) {
        const y = Math.round(base - (threshold.value / yMax) * fh) + 0.5;
        ctx.strokeStyle = c.line;
        ctx.lineWidth = 1;
        ctx.setLineDash([2, 4]);
        ctx.beginPath();
        ctx.moveTo(fx, y);
        ctx.lineTo(fx + fw, y);
        ctx.stroke();
        ctx.setLineDash([]);
        text(
            `${threshold.label} · ${threshold.note}`,
            fx + fw,
            y - 7,
            c.dim,
            'right',
        );
    }

    const axisY = base + 6.5;
    ctx.strokeStyle = c.line;
    ctx.beginPath();
    ctx.moveTo(fx, axisY);
    ctx.lineTo(fx + fw, axisY);
    ctx.stroke();
    for (const tick of ticks) {
        const x = Math.round(fx + tick.at * fw) + 0.5;
        ctx.beginPath();
        ctx.moveTo(x, axisY);
        ctx.lineTo(x, axisY + 4);
        ctx.stroke();
        text(
            tick.label,
            x,
            axisY + 18,
            c.dim,
            tick.at === 0 ? 'left' : tick.at === 1 ? 'right' : 'center',
        );
    }

    const marker = (
        index: number,
        title: string,
        sub: string,
        color: string,
    ) => {
        const value = values[index] ?? 0;
        const x = Math.round(fx + ((index + 0.5) / values.length) * fw) + 0.5;
        const ridgeY = base - ((value * grow) / yMax) * fh;
        ctx.strokeStyle = color;
        ctx.beginPath();
        ctx.moveTo(x, 18);
        ctx.lineTo(x, ridgeY - 4);
        ctx.stroke();
        const align: CanvasTextAlign = x > fw - 90 ? 'right' : 'left';
        const tx = align === 'left' ? x + 8 : x - 8;
        text(title, tx, 8, c.ink, align);
        text(sub, tx, 21, c.dim, align);
    };

    if (hover.value !== null && values[hover.value] !== null) {
        marker(
            hover.value,
            `${values[hover.value]}${unit}`,
            labels[hover.value] ?? '',
            c.ink,
        );
    } else if (known.length) {
        const index = values.lastIndexOf(peak);
        marker(index, `Peak ${peak}${unit}`, labels[index] ?? '', c.dim);
    }
}

function resize(): void {
    const el = stage.value;
    if (!el) return;
    width = el.clientWidth;
    height = el.clientHeight;
    draw();
}

function onMove(event: PointerEvent): void {
    const fw = Math.max(40, width - RIGHT);
    const x = event.offsetX;
    hover.value =
        x < 0 || x > fw
            ? null
            : clamp(Math.floor((x / fw) * values.length), 0, values.length - 1);
}

onMounted(() => {
    resize();
    if (stage.value && 'ResizeObserver' in window) {
        observer = new ResizeObserver(resize);
        observer.observe(stage.value);
    }

    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const start = performance.now();
        grow = 0;
        const step = (now: number) => {
            const t = clamp((now - start) / 700, 0, 1);
            grow = 1 - (1 - t) ** 3;
            draw();
            if (t < 1) raf = requestAnimationFrame(step);
        };
        raf = requestAnimationFrame(step);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    cancelAnimationFrame(raf);
});

watch(
    () => [values, isDark.value, hover.value],
    () => draw(),
    { deep: true },
);
</script>

<template>
    <div
        ref="stage"
        class="relative h-full w-full"
        role="img"
        :aria-label="label"
        @pointermove="onMove"
        @pointerleave="hover = null"
    >
        <canvas ref="canvas" class="absolute inset-0 size-full" />
        <div
            v-if="empty"
            class="absolute inset-x-0 top-1/2 flex -translate-y-1/2 flex-col items-center gap-1 text-center font-ui-mono text-[11px]/4 uppercase"
        >
            <span class="text-(--ds-text-secondary)">{{
                $t('No CPU samples in the last hour')
            }}</span>
            <span class="text-(--ds-text-faint)">{{
                $t('Machines report every minute while connected')
            }}</span>
        </div>
    </div>
</template>
