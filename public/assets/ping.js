'use strict';
// Heartbeat: tells the server this visitor is still on the page (for "online now").
(() => {
  const meta = document.querySelector('meta[name="csrf"]');
  if (!meta) return;
  const ping = () => {
    if (document.hidden) return;
    fetch('api.php', {method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': meta.content}, body: JSON.stringify({action: 'ping'})}).catch(() => {});
  };
  setInterval(ping, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) ping(); });
})();
