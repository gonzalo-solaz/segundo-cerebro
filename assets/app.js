// Comportamiento mínimo de la interfaz. Sin librerías y sin JS en línea
// (la CSP lo prohíbe): todo va por atributos data-*.
(function () {
  'use strict';
  var raiz = document.documentElement;

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-accion]');
    if (!b) return;
    var accion = b.getAttribute('data-accion');

    if (accion === 'tema') {
      var nuevo = raiz.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      raiz.setAttribute('data-theme', nuevo);
      try { localStorage.setItem('cerebro-tema', nuevo); } catch (e) { /* sin almacenamiento */ }
    }

    if (accion === 'menu') {
      document.body.classList.toggle('menu-abierto');
    }

    // Una sugerencia («ITV», «IBI»...) rellena el formulario de recordatorio.
    // La fecha NO: esa la pone siempre la persona.
    if (accion === 'sugerencia') {
      var f = document.getElementById(b.getAttribute('data-form'));
      if (!f) return;
      f.querySelector('[name=titulo]').value = b.getAttribute('data-titulo');
      var rep = f.querySelector('[name=repetir_meses]');
      if (rep) rep.value = b.getAttribute('data-repetir');
      var av = f.querySelector('[name=aviso_dias]');
      if (av) av.value = b.getAttribute('data-aviso');
      var fecha = f.querySelector('[name=fecha]');
      if (fecha) fecha.focus();
    }
  });

  // Confirmación antes de borrar o archivar.
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute('data-confirmar');
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  // Selectores que guardan al cambiar (rol y persona en Ajustes).
  document.addEventListener('change', function (ev) {
    if (ev.target.hasAttribute('data-auto-enviar') && ev.target.form) ev.target.form.submit();
  });

  // «#nuevo» en la URL abre el formulario de recordatorio.
  function abrirAncla() {
    if (!location.hash) return;
    var el = document.getElementById(location.hash.slice(1));
    if (el && el.tagName === 'DETAILS') { el.open = true; el.scrollIntoView({ block: 'start' }); }
  }
  window.addEventListener('hashchange', abrirAncla);
  document.addEventListener('DOMContentLoaded', abrirAncla);

  // Los avisos se borran solos a los pocos segundos.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.flash-ok').forEach(function (el) {
      setTimeout(function () { el.classList.add('flash-fuera'); }, 4500);
    });
  });

  // App instalada (PWA): los enlaces internos con target="_blank" se abren en la
  // propia app. En iOS el visor que abre un _blank usa OTRO almacén de cookies y
  // llega sin sesión (lección de van4ever; afecta a archivo.php). Los externos
  // siguen abriéndose fuera.
  var instalada = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
               || window.navigator.standalone === true;
  if (instalada) {
    document.addEventListener('click', function (ev) {
      var a = ev.target.closest ? ev.target.closest('a[target="_blank"]') : null;
      if (!a || !a.href) return;
      var destino;
      try { destino = new URL(a.href, location.href); } catch (e) { return; }
      if (destino.origin !== location.origin) return;
      ev.preventDefault();
      location.href = a.href;
    });
  }

  // Service worker: solo para que el navegador ofrezca «Instalar». No cachea nada.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () { /* sin PWA, la web va igual */ });
    });
  }
})();
