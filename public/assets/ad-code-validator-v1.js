/*
 * Ad Code Validator V1 — indikator "This HTML code is valid" gaya WPS/RetroTube.
 * Menempel kotak status hijau/merah di bawah tiap kolom kode iklan.
 * Pengecekan sengaja LONGGAR (kode jaringan iklan sering tampak aneh tapi valid):
 * hanya menandai error saat tag jelas rusak.
 */
(() => {
    'use strict';

    // Hanya kolom kode iklan (bukan judul, SEO, deskripsi, dsb).
    const NAME_RE = /(^code$|_code$|_zone[12]$)/;

    function checkHtml(raw) {
        const c = (raw || '').trim();
        if (!c) return { state: 'empty', msg: 'Kolom kosong' };

        const openScript = (c.match(/<script\b/gi) || []).length;
        const closeScript = (c.match(/<\/script>/gi) || []).length;
        if (openScript !== closeScript) {
            return { state: 'error', msg: 'Tag <script> tidak seimbang (' + openScript + ' buka / ' + closeScript + ' tutup)' };
        }

        const openStyle = (c.match(/<style\b/gi) || []).length;
        const closeStyle = (c.match(/<\/style>/gi) || []).length;
        if (openStyle !== closeStyle) {
            return { state: 'error', msg: 'Tag <style> tidak seimbang' };
        }

        // Ada '<' terakhir yang tidak pernah ditutup '>'.
        if (c.lastIndexOf('<') > c.lastIndexOf('>')) {
            return { state: 'error', msg: 'Ada tag "<" yang belum ditutup ">"' };
        }

        // Tag <iframe> yang dibuka tapi tak ditutup (iframe wajib punya penutup).
        const openIframe = (c.match(/<iframe\b/gi) || []).length;
        const closeIframe = (c.match(/<\/iframe>/gi) || []).length;
        if (openIframe !== closeIframe) {
            return { state: 'error', msg: 'Tag <iframe> tidak seimbang' };
        }

        return { state: 'valid', msg: 'This HTML code is valid.' };
    }

    function injectStyleOnce() {
        if (document.getElementById('al-adval-style')) return;
        const s = document.createElement('style');
        s.id = 'al-adval-style';
        s.textContent = `
.al-adval{margin:6px 0 10px;padding:9px 12px;border-radius:9px;font:600 12px/1.35 system-ui,Arial,sans-serif;display:flex;gap:8px;align-items:flex-start}
.al-adval--valid{color:#0b6b2f;background:#e6f6ec;border:1px solid #b7e3c6}
.al-adval--error{color:#8a1c1c;background:#fdeaea;border:1px solid #f2c2c2}
.al-adval--empty{color:#667085;background:#f2f4f7;border:1px solid #e2e6ec}
.al-adval__ic{font-weight:900}
`;
        document.head.appendChild(s);
    }

    function attach(textarea) {
        if (textarea.dataset.alValBound) return;
        textarea.dataset.alValBound = '1';

        const box = document.createElement('div');
        box.className = 'al-adval al-adval--empty';
        box.setAttribute('aria-live', 'polite');
        const ic = document.createElement('span');
        ic.className = 'al-adval__ic';
        const txt = document.createElement('span');
        box.append(ic, txt);

        // Sisipkan tepat setelah textarea.
        textarea.insertAdjacentElement('afterend', box);

        let timer = null;
        function run() {
            const r = checkHtml(textarea.value);
            box.className = 'al-adval al-adval--' + r.state;
            ic.textContent = r.state === 'valid' ? '✓' : (r.state === 'error' ? '✕' : '•');
            txt.textContent = r.msg;
        }
        function debounced() {
            if (timer) clearTimeout(timer);
            timer = setTimeout(run, 250);
        }

        textarea.addEventListener('input', debounced);
        textarea.addEventListener('blur', run);
        run();
    }

    function init() {
        const list = document.querySelectorAll('textarea[name]');
        let found = false;
        list.forEach((ta) => {
            if (NAME_RE.test(ta.getAttribute('name') || '')) {
                found = true;
                attach(ta);
            }
        });
        if (found) injectStyleOnce();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
