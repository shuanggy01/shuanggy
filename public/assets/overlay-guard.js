'use strict';
// "Kode bebas" helper: a transparent full-screen overlay added by third-party code is moved
// into the player, so it only covers the video area instead of the whole page. It catches one
// tap and then steps aside, so the player and the rest of the page never stay blocked.
(() => {
  const shell = document.getElementById('player-shell');
  if (!shell) return;
  const SKIP = 'HTML,HEAD,BODY,SCRIPT,STYLE,LINK,META,NOSCRIPT,TEMPLATE,HEADER,MAIN,FOOTER,NAV';
  const alpha = c => {
    const m = /rgba?\(([^)]+)\)/.exec(c || ''); if (!m) return c === 'transparent' ? 0 : 1;
    const p = m[1].split(',').map(parseFloat); return p.length > 3 ? p[3] : 1;
  };
  const ours = el => el.id === 'popup-code' || el.classList.contains('agegate') || el.classList.contains('toast') || el.hasAttribute('data-adbox');
  const isOverlay = el => {
    if (!(el instanceof HTMLElement) || el.matches(SKIP) || el.dataset.vidukOverlay || shell.contains(el) || ours(el)) return false;
    const cs = getComputedStyle(el);
    if ((cs.position !== 'fixed' && cs.position !== 'absolute') || cs.display === 'none' || cs.visibility === 'hidden' || cs.pointerEvents === 'none') return false;
    const r = el.getBoundingClientRect(), vw = innerWidth, vh = innerHeight;
    if (r.width < vw * 0.7 || r.height < vh * 0.7) return false;
    const clear = parseFloat(cs.opacity) <= 0.05;
    if (clear) return true;
    if (el.tagName === 'IFRAME' || alpha(cs.backgroundColor) > 0.05) return false;
    // Transparent background and nothing visible inside: a click catcher.
    return !(el.innerText || '').trim() && !el.querySelector('img,video,canvas,svg,iframe');
  };
  const move = el => {
    el.dataset.vidukOverlay = '1';
    shell.appendChild(el);
    const set = (k, v) => el.style.setProperty(k, v, 'important');
    set('position', 'absolute'); set('top', '0'); set('left', '0'); set('right', '0'); set('bottom', '0');
    set('width', '100%'); set('height', '100%'); set('max-width', 'none'); set('max-height', 'none');
    set('margin', '0'); set('transform', 'none'); set('z-index', '4');
    let done = false;
    const release = () => { if (done) return; done = true; setTimeout(() => set('pointer-events', 'none'), 400); };
    ['click', 'touchend', 'mouseup'].forEach(t => el.addEventListener(t, release, true));
  };
  const sweep = () => {
    for (const root of [document.body, document.documentElement])
      for (const el of Array.from(root.children)) if (isOverlay(el)) move(el);
  };
  sweep();
  new MutationObserver(() => { sweep(); setTimeout(sweep, 300); setTimeout(sweep, 1500); }).observe(document.documentElement, { childList: true, subtree: false });
  new MutationObserver(() => { sweep(); setTimeout(sweep, 300); setTimeout(sweep, 1500); }).observe(document.body, { childList: true });
  // Overlays that get their size/opacity after being inserted, or are re-armed later.
  let n = 0; const t = setInterval(() => { sweep(); if (++n > 40) { clearInterval(t); setInterval(sweep, 2500); } }, 500);
})();
