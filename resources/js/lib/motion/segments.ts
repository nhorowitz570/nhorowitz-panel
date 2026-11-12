// The selected thumb of every segmented control (.ds-segments) slides to the
// option you pick. CSS draws the thumb (app.css, `.ds-segments[data-thumb]`);
// this only measures the selected option and hands its box over as custom
// properties. Pages are rebuilt on navigation, so a control that comes back
// with the same options starts from where its thumb last was and slides on.

type Box = { x: number; y: number; w: number; h: number };

const ACTIVE = [
    ":scope > a[aria-current='page']",
    ":scope > button[aria-pressed='true']",
    ":scope > [role='tab'][aria-selected='true']",
].join(', ');

const last = new Map<string, Box>();
const tracked = new WeakSet<HTMLElement>();
let resize: ResizeObserver | undefined;

function reduced(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function keyOf(el: HTMLElement): string {
    return Array.from(el.children, (child) =>
        (child.textContent ?? '').replace(/\d+/g, '').trim(),
    ).join('|');
}

function boxOf(el: HTMLElement): Box | null {
    const active = el.querySelector<HTMLElement>(ACTIVE);

    if (!active || active.offsetWidth === 0) {
        return null;
    }

    return {
        x: active.offsetLeft,
        y: active.offsetTop,
        w: active.offsetWidth,
        h: active.offsetHeight,
    };
}

function apply(el: HTMLElement, box: Box | null): void {
    if (!box) {
        el.style.setProperty('--thumb-o', '0');

        return;
    }

    el.style.setProperty('--thumb-x', `${box.x}px`);
    el.style.setProperty('--thumb-y', `${box.y}px`);
    el.style.setProperty('--thumb-w', `${box.w}px`);
    el.style.setProperty('--thumb-h', `${box.h}px`);
    el.style.setProperty('--thumb-o', '1');
}

function place(el: HTMLElement): void {
    const box = boxOf(el);
    apply(el, box);

    if (box) {
        last.set(keyOf(el), box);
    }
}

function adopt(el: HTMLElement): void {
    if (tracked.has(el)) {
        return;
    }

    tracked.add(el);
    el.dataset.thumb = 'on';

    const key = keyOf(el);
    const previous = last.get(key);
    const now = boxOf(el);

    if (
        previous &&
        now &&
        !reduced() &&
        (previous.x !== now.x || previous.w !== now.w)
    ) {
        apply(el, previous);
        el.dataset.thumb = 'glide';
        // Settle the start position before moving to the new one.
        void getComputedStyle(el, '::before').transform;
        requestAnimationFrame(() => place(el));
    } else {
        place(el);
        requestAnimationFrame(() => {
            el.dataset.thumb = 'glide';
        });
    }

    resize?.observe(el);
}

function scan(root: ParentNode): void {
    root.querySelectorAll<HTMLElement>('.ds-segments').forEach(adopt);
}

function segmentOf(node: Node | null): HTMLElement | null {
    return node instanceof HTMLElement && node.classList.contains('ds-segments')
        ? node
        : null;
}

export function initSegmentSlider(): void {
    if (typeof window === 'undefined' || !('MutationObserver' in window)) {
        return;
    }

    resize =
        'ResizeObserver' in window
            ? new ResizeObserver((entries) =>
                  entries.forEach((entry) =>
                      place(entry.target as HTMLElement),
                  ),
              )
            : undefined;

    scan(document);

    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'attributes') {
                const segment = segmentOf(
                    (mutation.target as Element).parentElement,
                );

                if (segment) {
                    if (tracked.has(segment)) {
                        place(segment);
                    } else {
                        adopt(segment);
                    }
                }

                continue;
            }

            const target = segmentOf(mutation.target);

            if (target && tracked.has(target)) {
                place(target);
            }

            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                const segment = segmentOf(node);

                if (segment) {
                    adopt(segment);
                }

                scan(node);
            });
        }
    }).observe(document.body, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['aria-current', 'aria-pressed', 'aria-selected'],
    });
}
