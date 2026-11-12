import DOMPurify from 'dompurify';
import { marked } from 'marked';

function allowed(href: string): boolean {
    try {
        const url = new URL(href, window.location.href);
        if (url.protocol === 'mailto:') return true;

        const site = window.location.hostname.split('.').slice(-2).join('.');
        return (
            url.protocol === 'https:' &&
            [site, `docs.${site}`, `status.${site}`].includes(url.hostname)
        );
    } catch {
        return false;
    }
}

// No DOM under SSR: DOMPurify can't sanitise there, so renderMarkdown returns ''.
if (DOMPurify.isSupported)
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        if (node.tagName !== 'A') return;

        if (!allowed(node.getAttribute('href') ?? '')) {
            node.removeAttribute('href');
            return;
        }
        const href = node.getAttribute('href') ?? '';
        const url = new URL(href, window.location.href);
        if (node.textContent === href && url.protocol === 'https:') {
            node.textContent = (url.host + url.pathname).replace(/\/$/, '');
        }
        if (
            url.host === window.location.host &&
            url.pathname === '/register' &&
            !url.searchParams.has('utm_source') &&
            !window.location.hostname.startsWith('docs.')
        ) {
            url.searchParams.set('utm_source', 'ask-serveros');
            url.searchParams.set('utm_medium', 'chat');
            node.setAttribute('href', url.toString());
        }
        if (url.host !== window.location.host) {
            node.setAttribute('target', '_blank');
            node.setAttribute('rel', 'noopener');
        }
    });

export function renderMarkdown(markdown: string): string {
    if (!DOMPurify.isSupported) return '';

    const plain = markdown
        .replace(/ *[\u2013\u2014] +/g, ', ')
        .replace(/[\u2013\u2014]/g, '-');

    return DOMPurify.sanitize(
        marked.parse(plain, { gfm: true, breaks: true, async: false }),
        {
            // No images or media: fetching a URL from an answer could leak what the model read.
            FORBID_TAGS: [
                'img',
                'picture',
                'source',
                'video',
                'audio',
                'track',
                'svg',
                'math',
                'iframe',
                'object',
                'embed',
                'form',
                'input',
                'button',
                'style',
            ],
            FORBID_ATTR: ['style', 'srcset', 'background', 'poster'],
        },
    );
}
