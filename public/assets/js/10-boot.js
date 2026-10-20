(async function boot() {
  try { const saved = await DataStore.load(); if (saved && saved.version === 1) state = normalizeState(saved); } catch (e) { console.error(e); }
  if (!state.settings.pin) unlocked = true;
  if (ui.lens !== 'all' && !brand(ui.lens)) ui.lens = 'all';
  const r = location.hash.replace('#/', ''); if (r && VIEWS[r]) ui.route = r;
  applyTheme(); render();
})();
