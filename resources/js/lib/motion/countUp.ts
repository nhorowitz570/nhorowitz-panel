// v-count-up: numbers in an element's text count up when it first shows, and
// count from the old value to the new one when it changes ("44%", "18/18",
// "12.8 GiB"). The element must hold just its text. The text node Vue made is
// updated in place, so Vue keeps patching it as usual.
import type { Directive } from 'vue';

const NUMBER = /\d+(?:[.,]\d+)?/g;
const DURATION = 600;

type State = { raf: number; shown: string; written: string };

const states = new WeakMap<HTMLElement, State>();

function textNode(el: HTMLElement): Text | null {
    return el.childNodes.length === 1 && el.firstChild instanceof Text
        ? el.firstChild
        : null;
}

function parse(value: string): number {
    return parseFloat(value.replace(',', '.'));
}

function run(el: HTMLElement, from: string, to: string): void {
    const node = textNode(el);
    const state = states.get(el);

    if (!node || !state) {
        return;
    }

    cancelAnimationFrame(state.raf);
    state.shown = to;

    const ends = to.match(NUMBER) ?? [];

    if (
        ends.length === 0 ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        node.nodeValue = to;
        state.written = to;

        return;
    }

    const starts = (from.match(NUMBER) ?? []).map(parse);
    const literals = to.split(NUMBER);
    const start = performance.now();

    const step = (now: number) => {
        const t = Math.min(1, (now - start) / DURATION);
        const k = 1 - (1 - t) ** 3;
        let out = literals[0] ?? '';

        ends.forEach((end, index) => {
            if (t >= 1) {
                out += end;
            } else {
                const target = parse(end);
                const begin =
                    starts.length === ends.length ? (starts[index] ?? 0) : 0;
                const decimals = (end.split(/[.,]/)[1] ?? '').length;
                const value = (begin + (target - begin) * k).toFixed(decimals);
                out += end.includes(',') ? value.replace('.', ',') : value;
            }

            out += literals[index + 1] ?? '';
        });

        node.nodeValue = out;
        state.written = out;

        if (t < 1) {
            state.raf = requestAnimationFrame(step);
        }
    };

    // First frame now, so the final value never flashes before the count.
    step(start);
}

export const countUp: Directive<HTMLElement> = {
    mounted(el) {
        const to = el.textContent ?? '';
        states.set(el, { raf: 0, shown: to, written: to });
        run(el, '', to);
    },
    updated(el) {
        const state = states.get(el);
        const now = el.textContent ?? '';

        // Text we wrote ourselves (mid-count) isn't a change; anything else
        // came from Vue, so count from what was showing to the new value.
        if (state && now !== state.written) {
            run(el, state.shown, now);
        }
    },
    beforeUnmount(el) {
        const state = states.get(el);

        if (state) {
            cancelAnimationFrame(state.raf);
        }
    },
};
