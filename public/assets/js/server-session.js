(() => {
  'use strict';
  let lastInput = Date.now();
  let lastTouch = 0;
  let csrf = '';
  let pending = false;
  let leaving = false;
  function expired() {
    if (leaving) return;
    leaving = true;
    location.replace('login.php?expired=1');
  }
  async function check() {
    if (pending || leaving) return;
    pending = true;
    try {
      const response = await fetch('api.php?action=session', {credentials:'same-origin',cache:'no-store'});
      if (response.status === 401) { expired(); return; }
      if (!response.ok) return; // Transport/server failure is not an instruction to delete local business data.
      const data = await response.json();
      if (!data.authenticated) { expired(); return; }
      csrf = data.csrf;
      if (!document.hidden && Date.now() - lastInput < 60000 && Date.now() - lastTouch >= 60000) {
        lastTouch = Date.now();
        const touch = await fetch('api.php?action=touch', {method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf}});
        if (touch.status === 401) expired();
      }
    } catch (_) { /* Offline local state remains unchanged; next focus/poll retries the server. */ }
    finally { pending = false; }
  }
  ['pointerdown','keydown','touchstart'].forEach(type => document.addEventListener(type, () => { lastInput = Date.now(); }, {passive:true}));
  window.addEventListener('focus', check);
  window.addEventListener('pageshow', check);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
  // This adds server account actions without changing any existing dashboard screen/layout.
  document.addEventListener('keydown', event => {
    if (event.altKey && event.shiftKey && event.code === 'KeyQ') { event.preventDefault(); location.assign('logout.php'); }
  });
  setInterval(check, 30000);
  check();
})();
