<?php
// Iconos en SVG en línea (trazo, estilo Lucide), sin librería ni CDN.
// Heredan el color del texto (currentColor).

function icono(string $nombre, string $clase = 'ico'): string {
    static $trazos = [
        'panel'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'casa'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
        'coche'     => '<path d="M5 17H3v-4.5l2.2-5.1A2 2 0 0 1 7 6h10a2 2 0 0 1 1.8 1.4l2.2 5.1V17h-2"/><circle cx="7.5" cy="17" r="2"/><circle cx="16.5" cy="17" r="2"/><path d="M9.5 17h5M3 12.5h18"/>',
        'salud'     => '<path d="M19.5 12.6 12 20l-7.5-7.4A5 5 0 1 1 12 6.1a5 5 0 1 1 7.5 6.5z"/><path d="M3.5 12h4l2-3 3 6 2-3h5.5"/>',
        'documento' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><circle cx="8.5" cy="11" r="2"/><path d="M5.5 16c.6-1.5 1.7-2.2 3-2.2s2.4.7 3 2.2"/><path d="M14.5 10h4M14.5 14h3"/>',
        'contrato'  => '<path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3v5h5"/><path d="M8.5 13h7M8.5 17h5"/>',
        'familia'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.8c1.9.7 3.1 2.4 3.5 5.2"/>',
        'agenda'    => '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
        'persona'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4.2-6 8-6s7 2 8 6"/>',
        'ajustes'   => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
        'salir'     => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h11"/>',
        'mas'       => '<path d="M12 5v14M5 12h14"/>',
        'check'     => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'editar'    => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
        'papelera'  => '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
        'clip'      => '<path d="M20 11.5l-8.2 8.2a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4l7.8-7.8"/>',
        'externo'   => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'luna'      => '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
        'sol'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'cerebro'   => '<path d="M9.5 4.2A3 3 0 0 0 6 7a3 3 0 0 0-2.2 5 3.5 3.5 0 0 0 2.7 5.7A2.8 2.8 0 0 0 12 19V6a2.5 2.5 0 0 0-2.5-1.8z"/><path d="M14.5 4.2A3 3 0 0 1 18 7a3 3 0 0 1 2.2 5 3.5 3.5 0 0 1-2.7 5.7A2.8 2.8 0 0 1 12 19"/><path d="M8 10.5a2 2 0 0 1 2 2M16 10.5a2 2 0 0 0-2 2"/>',
        'cartera'   => '<path d="M19 7V5.5A1.5 1.5 0 0 0 17.5 4H5a2 2 0 0 0 0 4h14a1 1 0 0 1 1 1v3"/><path d="M3 6v12a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-3"/><path d="M16 12h5v4h-5a2 2 0 0 1 0-4z"/>',
        'alerta'    => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4M12 17h.01"/>',
        'reloj'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'atras'     => '<path d="M15 5l-7 7 7 7"/>',
        'archivar'  => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/>',
        'historial' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
        'repetir'   => '<path d="M17 2l4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        'descarga'  => '<path d="M12 4v11M7 10l5 5 5-5"/><path d="M5 20h14"/>',
        'llave'     => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3M16 7l3 3M14 9l2 2"/>',
    ];
    $d = $trazos[$nombre] ?? $trazos['panel'];
    return '<svg class="' . e($clase) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}
