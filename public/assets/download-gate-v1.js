(() => {
    const gate =
        document.querySelector(
            '[data-download-gate]'
        );

    const count =
        document.querySelector(
            '[data-count]'
        );

    const label =
        document.querySelector(
            '[data-count-label]'
        );

    const progress =
        document.querySelector(
            '[data-progress]'
        );

    const button =
        document.querySelector(
            '[data-download-button]'
        );

    if (
        !gate
        || !count
        || !progress
        || !button
    ) {
        return;
    }

    const total =
        Math.max(
            1,
            parseInt(
                gate.dataset.seconds || '15',
                10
            )
        );

    let remaining = total;

    function render() {
        count.textContent =
            String(remaining);

        const done =
            (total - remaining)
            / total;

        progress.style.transform =
            'scaleX('
            + Math.min(1, done)
            + ')';

        if (remaining <= 0) {
            count.textContent = '✓';

            if (label) {
                label.textContent =
                    'download siap!';
            }

            progress.style.transform =
                'scaleX(1)';

            button.classList.remove(
                'disabled'
            );

            button.removeAttribute(
                'aria-disabled'
            );

            const span =
                button.querySelector('span');

            if (span) {
                span.textContent =
                    'Download Video';
            }
        }
    }

    render();

    const timer = setInterval(() => {
        remaining -= 1;

        if (remaining <= 0) {
            remaining = 0;
            render();
            clearInterval(timer);
            return;
        }

        render();
    }, 1000);
})();
