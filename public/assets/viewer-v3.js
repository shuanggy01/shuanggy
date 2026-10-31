'use strict';
(() => {
  const video = document.querySelector('#main-video');
  if (!video) return;
  // visualViewport follows the usable browser area, including browser toolbar changes.
  const viewport = window.visualViewport;
  const fit = () => {
    if (viewport && viewport.scale > 1.05) return;
    document.documentElement.style.setProperty('--viewer-height', `${Math.round(viewport ? viewport.height : window.innerHeight)}px`);
  };
  fit();
  window.addEventListener('resize', fit, {passive:true});
  viewport?.addEventListener('resize', fit, {passive:true});
  const remember = () => {
    if (!Number.isFinite(video.duration) || video.duration <= 0) return;
    try {
      const {id} = JSON.parse(document.querySelector('#player-data').textContent);
      sessionStorage.setItem(`video-duration:${id}`, String(video.duration));
    } catch {}
  };
  video.addEventListener('loadedmetadata', remember);
  video.addEventListener('durationchange', remember);
  remember();
})();
