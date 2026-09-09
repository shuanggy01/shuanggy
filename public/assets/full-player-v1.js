(() => {
    const shell =
        document.querySelector(
            '[data-full-player]'
        );

    const video =
        document.querySelector(
            '[data-video]'
        );

    const play =
        document.querySelector(
            '[data-play]'
        );

    const fullscreen =
        document.querySelector(
            '[data-fullscreen]'
        );

    const share =
        document.querySelector(
            '[data-share]'
        );

    const toast =
        document.querySelector(
            '[data-toast]'
        );

    if (!shell || !video) {
        return;
    }

    let hideTimer = null;

    function scheduleUiHide() {
        clearTimeout(hideTimer);

        shell.classList.remove(
            'ui-hidden'
        );

        if (!video.paused) {
            hideTimer = setTimeout(
                () => {
                    shell.classList.add(
                        'ui-hidden'
                    );
                },
                2200
            );
        }
    }

    function updatePlaying() {
        shell.classList.toggle(
            'is-playing',
            !video.paused
        );

        scheduleUiHide();
    }

    play?.addEventListener(
        'click',
        async () => {
            try {
                await video.play();
            } catch {
                // Native controls remain available.
            }

            scheduleUiHide();
        }
    );

    video.addEventListener(
        'play',
        updatePlaying
    );

    video.addEventListener(
        'pause',
        updatePlaying
    );

    video.addEventListener(
        'ended',
        updatePlaying
    );

    shell.addEventListener(
        'pointerdown',
        event => {
            if (
                event.target.closest(
                    'button,a'
                )
            ) {
                return;
            }

            shell.classList.remove(
                'ui-hidden'
            );

            scheduleUiHide();
        }
    );

    fullscreen?.addEventListener(
        'click',
        async () => {
            try {
                if (
                    document.fullscreenElement
                ) {
                    await document.exitFullscreen();
                    return;
                }

                if (
                    shell.requestFullscreen
                ) {
                    await shell.requestFullscreen();
                    return;
                }

                if (
                    video.webkitEnterFullscreen
                ) {
                    video.webkitEnterFullscreen();
                }
            } catch {
                // Browser may block fullscreen.
            }
        }
    );

    share?.addEventListener(
        'click',
        async () => {
            const url =
                shell.dataset.shareUrl
                || location.href;

            const title =
                shell.dataset.shareTitle
                || document.title;

            try {
                if (navigator.share) {
                    await navigator.share({
                        title,
                        url,
                    });

                    return;
                }

                await navigator.clipboard.writeText(
                    url
                );

                if (toast) {
                    toast.textContent =
                        'Link disalin';

                    toast.classList.add(
                        'show'
                    );

                    setTimeout(
                        () => {
                            toast.classList.remove(
                                'show'
                            );
                        },
                        1400
                    );
                }

            } catch (error) {
                if (
                    error
                    && error.name === 'AbortError'
                ) {
                    return;
                }

                try {
                    const temp =
                        document.createElement(
                            'textarea'
                        );

                    temp.value = url;

                    document.body.appendChild(
                        temp
                    );

                    temp.select();

                    document.execCommand(
                        'copy'
                    );

                    temp.remove();

                    if (toast) {
                        toast.classList.add(
                            'show'
                        );

                        setTimeout(
                            () => {
                                toast.classList.remove(
                                    'show'
                                );
                            },
                            1400
                        );
                    }
                } catch {
                    // No-op.
                }
            }
        }
    );

    updatePlaying();
})();
