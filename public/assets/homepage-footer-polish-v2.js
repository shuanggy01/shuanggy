(() => {
    'use strict';

    const path = location.pathname.replace(/\/+$/, '') || '/';

    if (path !== '/') {
        return;
    }

    document.body.classList.add('al-home-polish');

    const walker = document.createTreeWalker(
        document.body,
        NodeFilter.SHOW_TEXT
    );

    const textNodes = [];

    while (walker.nextNode()) {
        textNodes.push(walker.currentNode);
    }

    for (const node of textNodes) {
        const parent = node.parentElement;

        if (!parent) {
            continue;
        }

        if (
            parent.closest(
                '.site-legal-footer, script, style, textarea, input'
            )
        ) {
            continue;
        }

        let value = node.nodeValue || '';

        value = value.replace(
            /(\d+)\s+album\b/gi,
            '$1 Kategori'
        );

        if (value.trim().toLowerCase() === 'koleksi') {
            value = value.replace(/koleksi/i, 'Kategori');
        }

        node.nodeValue = value;
    }

    const legalFooter = document.querySelector(
        '.site-legal-footer'
    );

    if (!legalFooter) {
        return;
    }

    const legalTop =
        legalFooter.getBoundingClientRect().top
        + window.scrollY;

    const candidates = Array.from(
        document.querySelectorAll('section, footer, div')
    );

    for (const el of candidates) {
        if (
            el.closest('.site-legal-footer')
            || el.closest('header')
            || el.closest('nav')
        ) {
            continue;
        }

        const ownText = (el.innerText || '')
            .replace(/\s+/g, ' ')
            .trim();

        if (ownText !== 'AsupanLendir') {
            continue;
        }

        const rect = el.getBoundingClientRect();
        const top = rect.top + window.scrollY;

        if (
            top < legalTop
            && legalTop - top < 650
            && rect.height >= 60
        ) {
            el.classList.add('al-legacy-footer-hidden');
            break;
        }
    }

    let prev = legalFooter.previousElementSibling;

    for (let i = 0; i < 3 && prev; i++) {
        const current = prev;
        prev = prev.previousElementSibling;

        const text = (current.innerText || '')
            .replace(/\s+/g, '')
            .trim();

        const hasMedia = current.querySelector(
            'img,video,iframe,canvas,svg'
        );

        const rect = current.getBoundingClientRect();

        if (
            text === ''
            && !hasMedia
            && rect.height > 100
        ) {
            current.classList.add('al-legacy-footer-hidden');
        }
    }
})();
