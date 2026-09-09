/*
 * Full Player Ads — gaya seperti Clean Tube Player
 *
 * Fitur:
 *   - Before play overlay (dengan countdown + close button)
 *   - On pause overlay
 *   - Below player banner
 *   - Otomatis mendeteksi video di halaman
 *   - Mobile-friendly
 */

(() => {
    'use strict';

    const cfg = window.AL_FULL_PLAYER_ADS || null;

    if (!cfg || !cfg.enabled) {
        return;
    }

    const video = document.querySelector('video');

    if (!video || !video.parentElement) {
        return;
    }

    const host = video.parentElement;

    // ─── Helpers ─────────────────────────────────────────────────────

    function el(tag, attrs, children) {
        const node = document.createElement(tag);
        if (attrs) {
            for (const k in attrs) {
                if (k === 'class') node.className = attrs[k];
                else if (k === 'html') node.innerHTML = attrs[k];
                else if (k.startsWith('on') && typeof attrs[k] === 'function') {
                    node.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
                }
                else node.setAttribute(k, attrs[k]);
            }
        }
        if (children) {
            (Array.isArray(children) ? children : [children]).forEach(c => {
                if (c == null) return;
                if (typeof c === 'string') node.appendChild(document.createTextNode(c));
                else node.appendChild(c);
            });
        }
        return node;
    }

    // ─── Build Overlay Panel ─────────────────────────────────────────

    function buildOverlay(type, adCode, countdown) {
        const hasCountdown = typeof countdown === 'number' && countdown > 0;

        const label = el('span', { class: 'al-fp-ad-panel__label' }, 'Advertisement');

        const countdownEl = hasCountdown
            ? el('span', { class: 'al-fp-ad-panel__countdown' }, countdown + 's')
            : null;

        const header = el('div', { class: 'al-fp-ad-panel__header' }, [label, countdownEl]);

        const body = el('div', { class: 'al-fp-ad-panel__body', html: adCode || '' });

        const btnIcon = elNS('svg', {
            class: 'al-fp-ad-btn-close__icon',
            viewBox: '0 0 24 24',
            fill: 'none',
            stroke: 'currentColor',
            'stroke-width': '2',
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round'
        }, [
            elNS('polygon', { points: '5 3 19 12 5 21 5 3', fill: 'currentColor', stroke: 'none' })
        ]);

        const btnText = el('span', { class: 'al-fp-ad-btn-close__text' },
            hasCountdown ? `Tunggu ${countdown} detik...` : 'Close and play'
        );

        const btn = el('button', {
            class: 'al-fp-ad-btn-close',
            type: 'button',
            'aria-label': 'Close advertisement and play video'
        }, [btnIcon, btnText]);

        if (hasCountdown) {
            btn.disabled = true;
        }

        const footer = el('div', { class: 'al-fp-ad-panel__footer' }, [btn]);

        const panel = el('div', { class: 'al-fp-ad-panel' }, [header, body, footer]);

        const overlay = el('div', {
            class: 'al-fp-ad-overlay al-fp-ad-overlay--' + type,
            hidden: 'hidden'
        }, [panel]);

        // State countdown
        if (hasCountdown) {
            let remaining = countdown;
            const timer = setInterval(() => {
                remaining--;
                if (countdownEl) {
                    countdownEl.textContent = remaining + 's';
                }
                btnText.textContent = `Tunggu ${remaining} detik...`;
                if (remaining <= 0) {
                    clearInterval(timer);
                    btn.disabled = false;
                    btnText.textContent = '▶ LANJUT PUTAR';
                    if (countdownEl) {
                        countdownEl.textContent = 'Selesai';
                    }
                }
            }, 1000);

            // Simpan timer reference untuk cleanup
            overlay._countdownTimer = timer;
        }

        btn.addEventListener('click', () => {
            overlay.hidden = true;
            if (video.paused) {
                video.play().catch(() => {});
            }
        });

        return overlay;
    }

    function elNS(tag, attrs, children) {
        const ns = 'http://www.w3.org/2000/svg';
        const node = document.createElementNS(ns, tag);
        if (attrs) {
            for (const k in attrs) {
                node.setAttribute(k, attrs[k]);
            }
        }
        if (children) {
            children.forEach(c => node.appendChild(c));
        }
        return node;
    }

    // ─── Below Player Banner ─────────────────────────────────────────

    function buildBelowBanner(adCode) {
        const banner = el('div', { class: 'al-fp-below-ad', html: adCode || '' });
        return banner;
    }

    // ─── Init ────────────────────────────────────────────────────────

    host.classList.add('al-fp-ad-host');

    let beforeOverlay = null;
    let pauseOverlay = null;
    let hasPlayed = false;

    // Before Play
    if (cfg.before_play && cfg.before_play.active && cfg.before_play.code) {
        beforeOverlay = buildOverlay('before', cfg.before_play.code, cfg.before_play.countdown);
        host.appendChild(beforeOverlay);
        beforeOverlay.hidden = false;
    }

    // On Pause
    if (cfg.on_pause && cfg.on_pause.active && cfg.on_pause.code) {
        pauseOverlay = buildOverlay('pause', cfg.on_pause.code, 0);
        host.appendChild(pauseOverlay);
    }

    // Below Player
    if (cfg.below_player && cfg.below_player.active && cfg.below_player.code) {
        const banner = buildBelowBanner(cfg.below_player.code);
        if (host.parentNode) {
            host.parentNode.insertBefore(banner, host.nextSibling);
        }
    }

    // ─── Video Event Listeners ───────────────────────────────────────

    video.addEventListener('play', () => {
        hasPlayed = true;
        if (beforeOverlay) {
            beforeOverlay.hidden = true;
        }
        if (pauseOverlay) {
            pauseOverlay.hidden = true;
        }
    });

    video.addEventListener('pause', () => {
        // Jangan tampilkan pause overlay jika video baru pertama kali
        // dan belum pernah play (before overlay masih yang menangani)
        if (!hasPlayed) return;
        // Jangan tampilkan jika video sudah selesai
        if (video.ended) return;

        if (pauseOverlay) {
            pauseOverlay.hidden = false;
        }
    });

    video.addEventListener('ended', () => {
        if (pauseOverlay) {
            pauseOverlay.hidden = true;
        }
    });

    // ─── Expose for debugging ────────────────────────────────────────

    window.AL_FULL_PLAYER_ADS__instance = {
        beforeOverlay,
        pauseOverlay,
        host,
        video
    };

})();
