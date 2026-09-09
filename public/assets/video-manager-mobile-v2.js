(() => {
    const menus = document.querySelectorAll('[data-video-menu]');
    const buttons = document.querySelectorAll('[data-video-menu-open]');
    const toast = document.querySelector('[data-toast]');

    function closeAll(except = null) {
        menus.forEach(menu => {
            if (menu !== except) {
                menu.hidden = true;
            }
        });
    }

    buttons.forEach(button => {
        button.addEventListener('click', event => {
            event.stopPropagation();

            const card = button.closest('[data-video-card]');
            const menu = card?.querySelector('[data-video-menu]');

            if (!menu) {
                return;
            }

            const willOpen = menu.hidden;
            closeAll(menu);
            menu.hidden = !willOpen;
        });
    });

    document.addEventListener('click', event => {
        if (!event.target.closest('[data-video-menu]')) {
            closeAll();
        }
    });

    document.querySelectorAll('[data-copy-link]').forEach(button => {
        button.addEventListener('click', async () => {
            const link = button.dataset.copyLink || '';

            try {
                await navigator.clipboard.writeText(link);
            } catch {
                const input = document.createElement('textarea');
                input.value = link;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                input.remove();
            }

            if (toast) {
                toast.classList.add('show');

                setTimeout(
                    () => toast.classList.remove('show'),
                    1400
                );
            }

            closeAll();
        });
    });
})();
