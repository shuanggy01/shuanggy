(() => {
    const STORAGE_KEY = 'al_popunder_loaded_v1';
    const SCRIPT_URL =
        'https://embargotechniquebattle.com/cf/48/11/cf481195aae9d1cd1ef9da95192652bd.js';

    function alreadyLoaded() {
        try {
            return sessionStorage.getItem(STORAGE_KEY) === '1';
        } catch {
            return false;
        }
    }

    function markLoaded() {
        try {
            sessionStorage.setItem(STORAGE_KEY, '1');
        } catch {
            // sessionStorage may be unavailable in some browsers.
        }
    }

    function loadPopunder() {
        if (alreadyLoaded()) {
            return;
        }

        if (document.querySelector('script[data-al-popunder]')) {
            return;
        }

        const script = document.createElement('script');
        script.src = SCRIPT_URL;
        script.async = true;
        script.dataset.alPopunder = '1';

        script.addEventListener('load', () => {
            markLoaded();
        }, { once: true });

        script.addEventListener('error', () => {
            // Do not mark failed loads, so a future page can retry.
        }, { once: true });

        document.head.appendChild(script);
    }

    /*
     * Load after DOM ready so the provider can register its
     * user-interaction handler before the visitor taps Play.
     * The website itself only loads this script once per session.
     */
    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            loadPopunder,
            { once: true }
        );
    } else {
        loadPopunder();
    }
})();
