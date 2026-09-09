(() => {
    const tabs = document.querySelectorAll('[data-tab]');
    const panels = document.querySelectorAll('[data-panel]');
    const activeInput = document.querySelector('[data-active-tab-input]');

    function activate(name) {
        tabs.forEach(tab => {
            tab.classList.toggle(
                'active',
                tab.dataset.tab === name
            );
        });

        panels.forEach(panel => {
            panel.classList.toggle(
                'active',
                panel.dataset.panel === name
            );
        });

        if (activeInput) {
            activeInput.value = name;
        }

        history.replaceState(
            null,
            '',
            '#' + name
        );
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            activate(tab.dataset.tab || 'general');
        });
    });

    const initial =
        location.hash.replace('#', '')
        || 'general';

    const valid = Array.from(tabs).some(
        tab => tab.dataset.tab === initial
    );

    activate(valid ? initial : 'general');

    document
        .querySelectorAll('[data-color-picker]')
        .forEach(picker => {
            const key = picker.dataset.colorPicker;

            const text = document.querySelector(
                '[data-color-text="' + key + '"]'
            );

            if (!text) {
                return;
            }

            picker.addEventListener('input', () => {
                text.value =
                    picker.value.toUpperCase();

                refreshPreview();
            });

            text.addEventListener('input', () => {
                if (
                    /^#[0-9A-Fa-f]{6}$/
                    .test(text.value)
                ) {
                    picker.value = text.value;
                    refreshPreview();
                }
            });
        });

    const siteName =
        document.querySelector('[data-site-name]');

    const logoUrl =
        document.querySelector('[data-logo-url]');

    function refreshPreview() {
        const primary =
            document.querySelector(
                '[name="primary"]'
            )?.value
            || '#FFC107';

        const namePreview =
            document.querySelector(
                '[data-name-preview]'
            );

        if (namePreview && siteName) {
            namePreview.textContent =
                siteName.value.trim()
                || 'AsupanLendir';
        }

        const logoPreview =
            document.querySelector(
                '[data-logo-preview]'
            );

        if (
            logoPreview
            && logoUrl
            && logoUrl.value.trim()
        ) {
            logoPreview.src =
                logoUrl.value.trim();
        }

        document
            .querySelector(
                '[data-primary-preview]'
            )
            ?.style.setProperty(
                '--preview-primary',
                primary
            );
    }

    siteName?.addEventListener(
        'input',
        refreshPreview
    );

    logoUrl?.addEventListener(
        'input',
        refreshPreview
    );

    refreshPreview();
})();
