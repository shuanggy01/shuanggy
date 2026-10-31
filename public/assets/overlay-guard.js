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
  const hasContent = el => !!(el.innerText || '').trim() || !!el.querySelector('img,video,canvas,svg');
  const isOverlay = el => {
    if (!(el instanceof HTMLElement) || el.matches(SKIP) || el.dataset.vidukOverlay || shell.contains(el) || ours(el) || el.closest('[data-adbox],#popup-code,.agegate')) return false;
    const cs = getComputedStyle(el);
    if ((cs.position !== 'fixed' && cs.position !== 'absolute') || cs.display === 'none' || cs.visibility === 'hidden' || cs.pointerEvents === 'none') return false;
    const r = el.getBoundingClientRect(), vw = innerWidth, vh = innerHeight;
    if (r.width < vw * 0.7 || r.height < vh * 0.7) return false;
    if (el.tagName === 'IFRAME') return parseFloat(cs.opacity) <= 0.05 || alpha(cs.backgroundColor) <= 0.05;
    // Fade-in modals and visible dialogs have content; a click catcher has none.
    if (hasContent(el) || el.querySelector('iframe')) return false;
    return parseFloat(cs.opacity) <= 0.05 || alpha(cs.backgroundColor) <= 0.05;
  };
  const geometry = el => {
    const set = (k, v) => el.style.setProperty(k, v, 'important');
    set('position', 'absolute'); set('top', '0'); set('left', '0'); set('right', '0'); set('bottom', '0');
    set('width', '100%'); set('height', '100%'); set('max-width', 'none'); set('max-height', 'none');
    set('margin', '0'); set('transform', 'none'); set('z-index', '4');
    if (el.dataset.vidukReleased) set('pointer-events', 'none');
  };
  const move = el => {
    el.dataset.vidukOverlay = '1';
    shell.appendChild(el);
    geometry(el);
    const release = () => {
      if (el.dataset.vidukReleased) return;
      setTimeout(() => { el.dataset.vidukReleased = '1'; geometry(el); }, 400);
    };
    ['click', 'touchend', 'mouseup'].forEach(t => el.addEventListener(t, release, true));
    // The ad script may rewrite the style attribute later: put the geometry back.
    new MutationObserver(() => {
      const st = el.style;
      if (st.getPropertyValue('position') !== 'absolute' || st.getPropertyValue('width') !== '100%' || (el.dataset.vidukReleased && st.getPropertyValue('pointer-events') !== 'none')) geometry(el);
    }).observe(el, { attributes: true, attributeFilter: ['style'] });
  };
  const sweep = () => {
    for (const el of Array.from(document.body.querySelectorAll('*'))) if (isOverlay(el)) move(el);
    for (const el of Array.from(document.documentElement.children)) if (isOverlay(el)) move(el);
  };
  sweep();
  // Debounced: the page mutates often (player clock), a full scan every time would be wasteful.
  let queued = false;
  const later = () => {
    if (queued) return; queued = true;
    setTimeout(() => { queued = false; sweep(); }, 250);
    setTimeout(sweep, 1500);
  };
  new MutationObserver(later).observe(document.documentElement, { childList: true });
  new MutationObserver(later).observe(document.body, { childList: true, subtree: true });
  // Overlays that get their size/opacity after being inserted, or are re-armed later.
  let n = 0; const t = setInterval(() => { sweep(); if (++n > 40) { clearInterval(t); setInterval(sweep, 2500); } }, 500);
})();
