'use strict';
// Popup 18+ smartlink: shown when ▶ is tapped and at the configured seconds while the video plays.
(() => {
  const data = document.querySelector('#agegate-data');
  if (!data) return;
  let cfg; try { cfg = JSON.parse(data.textContent); } catch { return; }
  const links = (cfg.links || []).filter(u => /^https:\/\//.test(u));
  if (!links.length) return;
  const pick = () => links[Math.floor(Math.random() * links.length)];

  const wrap = document.createElement('div');
  wrap.className = 'agegate'; wrap.hidden = true;
  wrap.setAttribute('role', 'dialog'); wrap.setAttribute('aria-modal', 'true'); wrap.setAttribute('aria-labelledby', 'agegate-title');
  wrap.innerHTML = '<div class="agegate-box"><button type="button" class="agegate-x" aria-label="Tutup">✕</button>'
    + '<div class="agegate-18" aria-hidden="true">18</div><h2 id="agegate-title">Konfirmasi Usia</h2>'
    + '<p>Anda harus berusia 18+ untuk menonton video ini.</p>'
    + '<a class="agegate-btn agegate-yes" target="_blank" rel="noopener sponsored">✓ Ya, Saya 18+</a>'
    + '<a class="agegate-btn agegate-out" href="https://www.google.com/">Keluar</a>'
    + '<small>Tombol "Ya" juga membuka halaman sponsor di tab baru.</small></div>';
  const yes = wrap.querySelector('.agegate-yes');
  let after = null;

  const api = window.vidukAgeGate = { done: false, open };
  function open(cb) {
    after = cb || null; yes.href = pick();
    // Inside fullscreen the popup must live in the fullscreen element to be visible.
    (document.fullscreenElement || document.body).append(wrap);
    wrap.hidden = false; yes.focus({ preventScroll: true });
  }
  function close() {
    if (wrap.hidden) return;
    wrap.hidden = true; api.done = true;
    const cb = after; after = null; if (cb) cb();
  }
  // Let the browser open the sponsor tab first, then close and continue the video.
  yes.addEventListener('click', () => setTimeout(close, 0));
  wrap.querySelector('.agegate-x').addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

  const video = document.querySelector('#main-video'), fired = {};
  if (video && (cfg.times || []).length) video.addEventListener('timeupdate', () => {
    if (!wrap.hidden || video.paused) return;
    const t = video.currentTime;
    for (const s of cfg.times) if (!fired[s] && t >= s && t < s + 3) { fired[s] = true; open(null); break; }
  });
})();
