// Lo que corre DENTRO del iframe de finanzas-pantalla.php (lo carga finanzas,
// en su maqueta embebida, desde aquí). Mismo origen que la ventana de fuera,
// así que puede tocarla directamente. Tres cosas:
//  1) ajustar la altura del iframe a su contenido (sin barra de scroll doble);
//  2) seguir el tema claro/oscuro de fuera (el botón de la barra lateral);
//  3) tras cada carga (enviar un formulario, cambiar de vista), subir arriba:
//     es donde salen los mensajes de «hecho» o de error.
(function () {
  'use strict';
  var marco = null;
  try { marco = window.frameElement; } catch (e) { /* otro origen */ }
  if (!marco) return;

  function alto() {
    marco.style.height = Math.ceil(document.documentElement.getBoundingClientRect().height) + 'px';
  }
  alto();
  window.addEventListener('load', alto);
  if (window.ResizeObserver) new ResizeObserver(alto).observe(document.documentElement);

  var fuera = null;
  try { fuera = window.parent.document.documentElement; } catch (e) { /* sin acceso */ }
  if (fuera && window.MutationObserver) {
    var igualar = function () {
      var t = fuera.getAttribute('data-theme');
      if (t) document.documentElement.setAttribute('data-theme', t);
    };
    igualar();
    new MutationObserver(igualar).observe(fuera, { attributes: true, attributeFilter: ['data-theme'] });
  }

  // La primera carga entra por el pase (con redirecciones) y la página de fuera ya
  // está arriba; las siguientes (enviar un formulario, cambiar de vista) no
  // tienen redirección: ahí se sube.
  var nav = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
  if (nav && nav.redirectCount === 0) window.parent.scrollTo(0, 0);
})();
