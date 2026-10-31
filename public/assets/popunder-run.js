'use strict';
// Download page: run the "Popunder / Script halaman" code as soon as the page opens.
(() => {
  const t = document.getElementById('popup-code');
  if (!t) return;
  for (const n of [...t.content.childNodes]) {
    if (n.nodeName === 'SCRIPT') {
      const s = document.createElement('script');
      for (const at of n.attributes) s.setAttribute(at.name, at.value);
      s.text = n.textContent; document.body.append(s);
    } else document.body.append(n.cloneNode(true));
  }
})();
