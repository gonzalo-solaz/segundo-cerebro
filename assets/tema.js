// Tema claro/oscuro aplicado ANTES del primer pintado (se carga en <head>,
// sin defer) para que no haya parpadeo. Si nunca se ha elegido, el del sistema.
(function () {
  var t = null;
  try { t = localStorage.getItem('cerebro-tema'); } catch (e) { /* modo privado */ }
  if (t !== 'light' && t !== 'dark') {
    t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-theme', t);
})();
