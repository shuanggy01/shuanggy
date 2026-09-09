/*
 * Full Player Ads V3 — isolated /f/ monetization runtime.
 *
 * - Top / bottom floating ad
 * - First Play trigger
 * - Before Play overlay + countdown
 * - Pause Ad (lazy execution)
 * - Timed secondary trigger based on real watch time
 * - Shared frequency cap for aggressive triggers
 * - Dynamic HTML/JS injector that re-creates <script> nodes so they execute
 */
(() => {
    'use strict';

    const cfg = window.AL_FULL_PLAYER_ADS || null;
    if (!cfg || !cfg.enabled) return;

    const host = document.querySelector('[data-full-player]');
    const video = document.querySelector('[data-video]') || document.querySelector('video');
    const playButton = document.querySelector('[data-play]');
    if (!host || !video) return;

    host.classList.add('al-fp-ad-host');

    const videoKey = location.pathname.replace(/\/+$/, '') || location.pathname;
    const maxTriggers = Math.max(1, Number(cfg.frequency?.max_triggers || 2));
    const cooldownMinutes = Math.max(0, Number(cfg.frequency?.cooldown_minutes ?? 30));
    const cooldownMs = cooldownMinutes * 60 * 1000;
    const storageKey = 'al_fp_ads_frequency_v3';

    function getStore() {
        try {
            return cooldownMinutes === 0 ? window.sessionStorage : window.localStorage;
        } catch (_) {
            return null;
        }
    }

    function freshFrequencyState() {
        return { started_at: Date.now(), count: 0, videos: {} };
    }

    function loadFrequencyState() {
        const store = getStore();
        if (!store) return freshFrequencyState();
        try {
            const raw = store.getItem(storageKey);
            const parsed = raw ? JSON.parse(raw) : freshFrequencyState();
            if (!parsed || typeof parsed !== 'object') return freshFrequencyState();
            if (cooldownMs > 0 && Date.now() - Number(parsed.started_at || 0) >= cooldownMs) {
                return freshFrequencyState();
            }
            parsed.count = Math.max(0, Number(parsed.count || 0));
            parsed.videos = parsed.videos && typeof parsed.videos === 'object' ? parsed.videos : {};
            return parsed;
        } catch (_) {
            return freshFrequencyState();
        }
    }

    let frequencyState = loadFrequencyState();

    function saveFrequencyState() {
        const store = getStore();
        if (!store) return;
        try {
            store.setItem(storageKey, JSON.stringify(frequencyState));
        } catch (_) {
            // Storage may be disabled; runtime continues with in-memory state.
        }
    }

    function canFireAggressive(type) {
        const perVideo = frequencyState.videos[videoKey] || {};
        return frequencyState.count < maxTriggers && !perVideo[type];
    }

    function markAggressive(type) {
        frequencyState.count += 1;
        frequencyState.videos[videoKey] = frequencyState.videos[videoKey] || {};
        frequencyState.videos[videoKey][type] = true;
        saveFrequencyState();
    }

    function el(tag, attrs = {}, children = []) {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            if (key === 'class') node.className = value;
            else if (key === 'text') node.textContent = value;
            else if (key === 'html') node.innerHTML = value;
            else node.setAttribute(key, String(value));
        }
        for (const child of (Array.isArray(children) ? children : [children])) {
            if (child == null) continue;
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        }
        return node;
    }

    function elNS(tag, attrs = {}) {
        const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
        for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, String(value));
        return node;
    }

    function appendExecutableNode(parent, sourceNode) {
        if (sourceNode.nodeType === Node.TEXT_NODE) {
            parent.appendChild(document.createTextNode(sourceNode.textContent || ''));
            return;
        }
        if (sourceNode.nodeType !== Node.ELEMENT_NODE) return;

        if (sourceNode.tagName.toLowerCase() === 'script') {
            const script = document.createElement('script');
            for (const attr of sourceNode.attributes) script.setAttribute(attr.name, attr.value);
            if (!sourceNode.src) script.text = sourceNode.textContent || '';
            parent.appendChild(script);
            return;
        }

        const clone = sourceNode.cloneNode(false);
        parent.appendChild(clone);
        for (const child of sourceNode.childNodes) appendExecutableNode(clone, child);
    }

    function mountAdCode(container, code) {
        if (!container || !code) return;
        const template = document.createElement('template');
        template.innerHTML = code;
        const nodes = Array.from(template.content.childNodes);
        for (const node of nodes) appendExecutableNode(container, node);
    }

    function createTriggerMount(type) {
        const mount = el('div', {
            class: 'al-fp-trigger-mount',
            'data-trigger': type,
            'aria-hidden': 'true'
        });
        document.body.appendChild(mount);
        return mount;
    }

    function fireAggressive(type, triggerCfg) {
        if (!triggerCfg?.active || !triggerCfg.code || !canFireAggressive(type)) return false;
        // Mark first to prevent re-entrant ad code from causing duplicate triggers.
        markAggressive(type);
        const mount = createTriggerMount(type);
        mountAdCode(mount, triggerCfg.code);
        return true;
    }

    function buildFloatingAd(position, adCode) {
        const body = el('div', { class: 'al-fp-floating-ad__body' });
        const close = el('button', {
            class: 'al-fp-floating-ad__close',
            type: 'button',
            'aria-label': 'Tutup iklan',
            text: '×'
        });
        const box = el('div', { class: `al-fp-floating-ad al-fp-floating-ad--${position}` }, [body, close]);
        close.addEventListener('click', () => box.remove());
        host.appendChild(box);
        mountAdCode(body, adCode);
        return box;
    }

    function buildOverlay(type, adCode, countdownSeconds, onContinue) {
        const label = el('span', { class: 'al-fp-ad-panel__label', text: type === 'pause' ? 'Pause Advertisement' : 'Advertisement' });
        const countdownEl = el('span', { class: 'al-fp-ad-panel__countdown', text: '' });
        const header = el('div', { class: 'al-fp-ad-panel__header' }, [label, countdownEl]);
        const body = el('div', { class: 'al-fp-ad-panel__body' });

        const icon = elNS('svg', {
            class: 'al-fp-ad-btn-close__icon', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor',
            'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round'
        });
        icon.appendChild(elNS('polygon', { points: '5 3 19 12 5 21 5 3', fill: 'currentColor', stroke: 'none' }));

        const btnText = el('span', { class: 'al-fp-ad-btn-close__text', text: '▶ LANJUT PUTAR' });
        const btn = el('button', {
            class: 'al-fp-ad-btn-close', type: 'button', 'aria-label': 'Tutup iklan dan lanjut putar'
        }, [icon, btnText]);
        const footer = el('div', { class: 'al-fp-ad-panel__footer' }, [btn]);
        const panel = el('div', { class: 'al-fp-ad-panel' }, [header, body, footer]);
        const overlay = el('div', { class: `al-fp-ad-overlay al-fp-ad-overlay--${type}`, hidden: 'hidden' }, [panel]);

        let mounted = false;
        let timer = null;

        function activateCode() {
            if (mounted) return;
            mounted = true;
            mountAdCode(body, adCode);
        }

        function stopTimer() {
            if (timer) clearInterval(timer);
            timer = null;
        }

        function startCountdown() {
            stopTimer();
            let remaining = Math.max(0, Number(countdownSeconds || 0));
            if (remaining <= 0) {
                btn.disabled = false;
                countdownEl.textContent = '';
                btnText.textContent = '▶ LANJUT PUTAR';
                return;
            }
            btn.disabled = true;
            countdownEl.textContent = `${remaining}s`;
            btnText.textContent = `Tunggu ${remaining} detik...`;
            timer = setInterval(() => {
                remaining -= 1;
                if (remaining <= 0) {
                    stopTimer();
                    btn.disabled = false;
                    countdownEl.textContent = 'Siap';
                    btnText.textContent = '▶ LANJUT PUTAR';
                    return;
                }
                countdownEl.textContent = `${remaining}s`;
                btnText.textContent = `Tunggu ${remaining} detik...`;
            }, 1000);
        }

        overlay.show = () => {
            activateCode();
            overlay.hidden = false;
            startCountdown();
        };
        overlay.hide = () => {
            stopTimer();
            overlay.hidden = true;
        };

        btn.addEventListener('click', () => {
            overlay.hide();
            if (typeof onContinue === 'function') onContinue();
        });

        host.appendChild(overlay);
        return overlay;
    }

    // Persistent display slots execute immediately on /f/ only.
    if (cfg.top_ad?.active && cfg.top_ad.code) buildFloatingAd('top', cfg.top_ad.code);
    if (cfg.below_player?.active && cfg.below_player.code) buildFloatingAd('bottom', cfg.below_player.code);

    // First Play mendukung beberapa kode iklan sekaligus (mis. beberapa Smart Link/banner).
    // Pisahkan tiap kode dengan komentar penanda "<!-- @next -->" di kolom admin.
    // Kalau cuma ada satu kode (tanpa penanda), perilakunya tetap seperti versi lama:
    // dieksekusi diam-diam di background (cocok untuk Popunder murni).
    function splitAdSnippets(code) {
        if (!code) return [];
        return String(code)
            .split(/<!--\s*@next\s*-->/i)
            .map((s) => s.trim())
            .filter(Boolean);
    }

    const firstPlaySnippets = (cfg.first_play?.active && cfg.first_play.code)
        ? splitAdSnippets(cfg.first_play.code)
        : [];
    const firstPlayIsQueue = firstPlaySnippets.length > 1;

    function buildQueueOverlay(type, items, onAllDone) {
        let index = 0;
        const label = el('span', { class: 'al-fp-ad-panel__label', text: 'Advertisement' });
        const countdownEl = el('span', { class: 'al-fp-ad-panel__countdown', text: '' });
        const header = el('div', { class: 'al-fp-ad-panel__header' }, [label, countdownEl]);
        const body = el('div', { class: 'al-fp-ad-panel__body' });

        const icon = elNS('svg', {
            class: 'al-fp-ad-btn-close__icon', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor',
            'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round'
        });
        icon.appendChild(elNS('polygon', { points: '5 3 19 12 5 21 5 3', fill: 'currentColor', stroke: 'none' }));
        const btnText = el('span', { class: 'al-fp-ad-btn-close__text', text: '' });
        const btn = el('button', {
            class: 'al-fp-ad-btn-close', type: 'button', 'aria-label': 'Lanjut ke iklan berikutnya'
        }, [icon, btnText]);
        const footer = el('div', { class: 'al-fp-ad-panel__footer' }, [btn]);
        const panel = el('div', { class: 'al-fp-ad-panel' }, [header, body, footer]);
        const overlay = el('div', { class: `al-fp-ad-overlay al-fp-ad-overlay--${type}` }, [panel]);

        function showCurrent() {
            body.innerHTML = '';
            mountAdCode(body, items[index]);
            countdownEl.textContent = items.length > 1 ? `${index + 1}/${items.length}` : '';
            btnText.textContent = index < items.length - 1 ? '▶ LANJUT' : '▶ LANJUT PUTAR';
        }

        btn.addEventListener('click', () => {
            index += 1;
            if (index >= items.length) {
                overlay.remove();
                onAllDone();
                return;
            }
            showCurrent();
        });

        host.appendChild(overlay);
        showCurrent();
        return overlay;
    }

    let hasPlayed = false;
    let beforeConsumed = false;
    let firstPlayAttempted = false;

    const beforeOverlay = cfg.before_play?.active && cfg.before_play.code
        ? buildOverlay('before', cfg.before_play.code, cfg.before_play.countdown, () => {
            video.play().catch(() => {});
        })
        : null;

    const pauseOverlay = cfg.on_pause?.active && cfg.on_pause.code
        ? buildOverlay('pause', cfg.on_pause.code, 0, () => {
            video.play().catch(() => {});
        })
        : null;

    // Dipanggil setelah antrian First Play (kalau ada) selesai ditutup pengunjung.
    function proceedAfterFirstPlayQueue() {
        if (beforeOverlay && !beforeConsumed) {
            beforeConsumed = true;
            beforeOverlay.show();
            return;
        }
        hasPlayed = true;
        video.play().catch(() => {});
    }

    function handleFirstPlayGesture(event) {
        if (hasPlayed || firstPlayAttempted) return;
        firstPlayAttempted = true;

        if (firstPlayIsQueue) {
            // Beberapa kode Smart Link/banner: tampilkan satu-per-satu sebelum video mulai.
            event.preventDefault();
            event.stopImmediatePropagation();
            if (canFireAggressive('first_play')) {
                markAggressive('first_play');
                buildQueueOverlay('first', firstPlaySnippets, proceedAfterFirstPlayQueue);
            } else {
                proceedAfterFirstPlayQueue();
            }
            return;
        }

        // Perilaku lama: satu kode saja, dijalankan diam-diam di background.
        fireAggressive('first_play', cfg.first_play);

        if (beforeOverlay && !beforeConsumed) {
            beforeConsumed = true;
            event.preventDefault();
            event.stopImmediatePropagation();
            beforeOverlay.show();
        }
    }

    // Capture phase lets monetization run before the normal player click handler.
    playButton?.addEventListener('click', handleFirstPlayGesture, true);

    // Fallback for starting through native controls or another script.
    video.addEventListener('play', () => {
        if (!firstPlayAttempted) {
            firstPlayAttempted = true;

            if (firstPlayIsQueue) {
                video.pause();
                if (canFireAggressive('first_play')) {
                    markAggressive('first_play');
                    buildQueueOverlay('first', firstPlaySnippets, proceedAfterFirstPlayQueue);
                } else {
                    proceedAfterFirstPlayQueue();
                }
                return;
            }

            fireAggressive('first_play', cfg.first_play);
        }

        // Native video controls can bypass the custom center Play button.
        // In that path, immediately pause and present Before Play once.
        if (beforeOverlay && !beforeConsumed) {
            beforeConsumed = true;
            video.pause();
            beforeOverlay.show();
            return;
        }

        hasPlayed = true;
        beforeOverlay?.hide();
        pauseOverlay?.hide();
    });

    video.addEventListener('pause', () => {
        if (!hasPlayed || video.ended) return;
        pauseOverlay?.show();
    });

    video.addEventListener('ended', () => pauseOverlay?.hide());

    // Timed trigger counts real wall-clock watch time, not seek position.
    let watchedMs = 0;
    let watchStartedAt = null;
    let timedReady = false;
    let timedDone = false;
    const targetMs = Math.max(5, Number(cfg.timed?.after_seconds || 60)) * 1000;

    function updateWatched() {
        if (watchStartedAt == null) return;
        const now = performance.now();
        watchedMs += Math.max(0, now - watchStartedAt);
        watchStartedAt = now;

        if (!timedDone && !timedReady && cfg.timed?.active && cfg.timed.code && watchedMs >= targetMs) {
            timedReady = true;
            if (!cfg.timed.require_interaction) {
                timedDone = fireAggressive('timed', cfg.timed) || !canFireAggressive('timed');
                timedReady = !timedDone;
            }
        }
    }

    let watchTicker = null;
    function startWatchTicker() {
        if (!cfg.timed?.active || timedDone) return;
        watchStartedAt = performance.now();
        if (!watchTicker) watchTicker = setInterval(updateWatched, 1000);
    }
    function stopWatchTicker() {
        updateWatched();
        watchStartedAt = null;
        if (watchTicker) clearInterval(watchTicker);
        watchTicker = null;
    }

    video.addEventListener('playing', startWatchTicker);
    video.addEventListener('pause', stopWatchTicker);
    video.addEventListener('ended', stopWatchTicker);

    document.addEventListener('pointerdown', () => {
        if (!timedReady || timedDone || !cfg.timed?.require_interaction) return;
        timedDone = fireAggressive('timed', cfg.timed) || !canFireAggressive('timed');
        timedReady = !timedDone;
    }, true);

    window.addEventListener('pagehide', stopWatchTicker, { once: true });

    window.AL_FULL_PLAYER_ADS__instance = {
        host,
        video,
        beforeOverlay,
        pauseOverlay,
        getFrequencyState: () => ({ ...frequencyState }),
        getWatchedSeconds: () => Math.round(watchedMs / 1000),
    };
})();
