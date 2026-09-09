(() => {
    const switcher = document.getElementById('serverSwitcher');

    if (!switcher) {
        return;
    }

    const shell = document.getElementById('playerShell');
    const video = document.getElementById('videoPlayer');
    const frameWrap = document.getElementById('mirrorFrameWrap');
    const frame = document.getElementById('mirrorFrame');
    const buttons = switcher.querySelectorAll('[data-server-type]');

    if (!shell || !video || !frameWrap || !frame || !buttons.length) {
        return;
    }

    function activate(button) {
        const type = button.dataset.serverType || 'direct';

        buttons.forEach(item => item.classList.remove('active'));
        button.classList.add('active');

        if (type === 'direct') {
            frame.src = 'about:blank';
            frameWrap.hidden = true;
            shell.classList.remove('mirror-active');
            return;
        }

        const url = button.dataset.serverUrl || '';

        if (!/^https:\/\/(?:luluvdo\.com|vidara\.to)\/e\/[A-Za-z0-9_-]+$/i.test(url)) {
            return;
        }

        try {
            video.pause();
        } catch (e) {}

        frame.src = url;
        frameWrap.hidden = false;
        shell.classList.add('mirror-active');
    }

    buttons.forEach(button => {
        button.addEventListener('click', () => activate(button));
    });

    window.addEventListener('pagehide', () => {
        frame.src = 'about:blank';
    });
})();
