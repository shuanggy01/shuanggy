'use strict';
(() => {
  const box = document.querySelector('.download-wait');
  if (!box) return;
  const clock = document.querySelector('#download-clock');
  const progress = document.querySelector('#download-progress');
  const ready = document.querySelector('#download-ready');
  const remaining = Math.max(0, Number(box.dataset.remaining));
  const total = Number(box.dataset.total);
  const deadline = performance.now() + remaining * 1000;
  let timer;
  const update = () => {
    const left = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
    clock.textContent = `00:${String(left).padStart(2, '0')}`;
    progress.value = total - left;
    if (left === 0) {
      clearInterval(timer);
      document.querySelector('#download-status').textContent = 'Download siap';
      document.querySelector('#download-help').textContent = 'Video siap. Tekan tombol di bawah untuk membuka file.';
      ready.hidden = false;
    }
  };
  timer = setInterval(update, 250);
  document.addEventListener('visibilitychange', update);
  update();
  try {
    const seconds = Number(sessionStorage.getItem(`video-duration:${box.dataset.id}`));
    if (Number.isFinite(seconds) && seconds > 0) {
      const n = Math.floor(seconds);
      const time = [Math.floor(n / 3600), Math.floor(n / 60) % 60, n % 60].map(v => String(v).padStart(2, '0')).join(':');
      const duration = document.querySelector('#download-duration');
      duration.textContent = `Durasi: ${time}`;
      duration.hidden = false;
    }
  } catch {}
})();
