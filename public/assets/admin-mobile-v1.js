(() => {
    const body = document.body;
    const open = document.querySelector('[data-more-open]');
    const close = document.querySelector('[data-more-close]');
    const backdrop = document.querySelector('[data-more-backdrop]');
    const sheet = document.querySelector('[data-more-sheet]');

    function setOpen(value) {
        body.classList.toggle('am-more-open', value);

        if (sheet) {
            sheet.setAttribute(
                'aria-hidden',
                value ? 'false' : 'true'
            );
        }
    }

    open?.addEventListener('click', () => setOpen(true));
    close?.addEventListener('click', () => setOpen(false));
    backdrop?.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });
})();
