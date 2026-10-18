'use strict';
// Banner boxes (.ad-fit): scale a fixed-size ad (300x250, 728x90, ...) down to the space
// that is available, so it never widens the page; hide the box when no ad has filled it.
(() => {
  const boxes = [...document.querySelectorAll('[data-adbox]')];
  if (!boxes.length) return;
  const fit = box => {
    const f = box.querySelector('.ad-fit'); if (!f) return;
    const w = Number(f.dataset.w) || 1, avail = box.clientWidth || w;
    f.style.setProperty('--s', Math.min(1, avail / w).toFixed(4));
  };
  const filled = box => {
    const inner = box.querySelector('.ad-fit-inner,.ad-native') || box;
    for (const el of inner.querySelectorAll('iframe,img,video,canvas,svg,object,embed,ins')) {
      const r = el.getBoundingClientRect(); if (r.width >= 20 && r.height >= 20) return true;
    }
    return (inner.innerText || '').trim().length > 4;
  };
  let tick = 0;
  const run = () => {
    boxes.forEach(fit);
    // Nothing rendered after ~5s (ad blocker, no fill): collapse the empty gap; reopen if it fills later.
    if (tick >= 5) boxes.forEach(b => b.classList.toggle('ad-empty', !filled(b)));
  };
  run();
  addEventListener('resize', () => boxes.forEach(fit), { passive: true });
  if (window.ResizeObserver) boxes.forEach(b => new ResizeObserver(() => fit(b)).observe(b));
  // Fast at first, then a slow poll so an ad that fills late still reopens its box.
  const timer = setInterval(() => { tick++; run(); if (tick >= 30) { clearInterval(timer); setInterval(run, 4000); } }, 1000);
})();
