<?php
/**
 * Inferium: pinta las páginas de inferium.es desde un JSON central (patrón pedalada.es).
 *
 * Va en wp-content/novamira-sandbox/inferium.php. Los datos y recursos van en wp-content/inferium/.
 * Cada página de WordPress con el meta `_inferium_layout` se sirve como documento propio:
 *   inicio | categoria | ficha | catalogo | comparador | calculadora | como-elegimos | aviso
 * `_inferium_slug` indica la categoría o el producto. El tema activo no interviene.
 */

if (!defined('ABSPATH')) { exit; }

const INF_NOMBRE = 'Inferium';
const INF_AUTOR = 'Ernesto Santos';
const INF_VERSION = '1.8.0';
const INF_REL = 'sponsored nofollow noopener';

/* ---------- Datos ---------- */

function inf_dir() { return WP_CONTENT_DIR . '/inferium'; }
function inf_asset($f) { return content_url('inferium/assets/' . $f) . '?v=' . INF_VERSION; }

function inf_datos($nombre) {
    static $cache = [];
    if (!isset($cache[$nombre])) {
        $raw = @file_get_contents(inf_dir() . "/data/$nombre.json");
        $cache[$nombre] = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return $cache[$nombre];
}
function inf_productos() { return inf_datos('productos'); }
function inf_categorias() { return inf_datos('categorias'); }
function inf_modelos() { return inf_datos('modelos'); }
function inf_versus() { return inf_datos('versus'); }
function inf_grupos() { return inf_datos('accesorios'); }
function inf_guias() { return inf_datos('guias'); }
function inf_guia($slug) { foreach (inf_guias() as $g) { if ($g['slug'] === $slug) return $g; } return null; }
function inf_grupo_path($g) { return !empty($g['raiz']) ? '/' . $g['slug'] . '/' : '/componentes/' . $g['slug'] . '/'; }
function inf_item($id) { foreach (inf_grupos() as $g) { foreach ($g['items'] as $i) { if ($i['id'] === $id) return $i + ['grupo' => $g]; } } return null; }
function inf_corto($p) { return trim(preg_replace('/^(NVIDIA GeForce|AMD Radeon|NVIDIA|Apple|AMD)\s+|\s*\(.*\)$/u', '', $p['nombre'])); }
function inf_producto($slug) { foreach (inf_productos() as $p) { if ($p['slug'] === $slug) return $p; } return null; }
function inf_categoria($slug) { foreach (inf_categorias() as $c) { if ($c['slug'] === $slug) return $c; } return null; }

/** Categoría de tipo (tarjetas-graficas, mini-pc, mac, placas-ia) a la que pertenece un producto: es su carpeta en la URL. */
function inf_cat_tipo($p) { foreach (inf_categorias() as $c) { if (($c['tipo'] ?? '') === $p['tipo']) return $c; } return null; }
/** Ruta de la ficha: /<tipo>/<producto>/, por ejemplo /tarjetas-graficas/nvidia-rtx-5090/. */
function inf_ficha_path($p) { $c = inf_cat_tipo($p); return '/' . ($c ? $c['slug'] : 'hardware') . '/' . $p['slug'] . '/'; }
/** Una categoría de tipo agrupa todos los productos de ese tipo; una de uso, los que la llevan en `categorias`. */
function inf_en_categoria($p, $c) { return !empty($c['tipo']) ? $p['tipo'] === $c['tipo'] : in_array($c['slug'], $p['categorias'], true); }
function inf_de_categoria($c) { return array_values(array_filter(inf_productos(), fn($p) => inf_en_categoria($p, $c))); }

/* ---------- Enlaces ---------- */

/** Tag de Amazon Associates de esta web. Se guarda en la opción `inferium_amazon_tag`. */
function inf_tag() { return get_option('inferium_amazon_tag', 'PENDIENTE-21'); }

/** Con ASIN va a la ficha de Amazon; sin ASIN, a una búsqueda. Siempre con el tag. */
function inf_amazon_url($p) {
    $tag = rawurlencode(inf_tag());
    if (!empty($p['asin'])) return 'https://www.amazon.es/dp/' . rawurlencode($p['asin']) . '?tag=' . $tag;
    return 'https://www.amazon.es/s?k=' . rawurlencode($p['nombre']) . '&tag=' . $tag;
}

/** Enlace interno. Mientras la portada no sea la página de inicio de WordPress, "/" apunta al borrador /inicio/. */
function inf_url($path = '/') {
    if ($path === '/' && !inf_portada_activa()) $path = '/inicio/';
    return home_url($path);
}
function inf_portada_activa() {
    if (get_option('show_on_front') !== 'page') return false;
    $id = (int) get_option('page_on_front');
    return $id && get_post_meta($id, '_inferium_layout', true) === 'inicio';
}

/* ---------- Cálculos (los mismos de assets/inferium.js) ---------- */

function inf_vram_necesaria($m, $bits = 4.5) {
    if (!empty($m['fijo'])) return $bits >= 16 ? $m['fijo'] * 1.6 : $m['fijo'];
    return $m['params'] * $bits / 8 * 1.2;
}
function inf_cabe($m, $vram, $bits = 4.5) { return inf_vram_necesaria($m, $bits) <= $vram; }

/** Tokens/s estimados: ancho de banda × 0,7 ÷ tamaño de los pesos. Estimación propia, no benchmark. */
function inf_tokens($p, $m, $bits = 4.5) {
    if ($m['tipo'] !== 'texto' || !inf_cabe($m, $p['vram'], $bits)) return null;
    return (int) round($p['anchoBanda'] * 0.7 / ($m['params'] * $bits / 8));
}
function inf_puntuaciones($p) {
    $clamp = fn($v) => (int) max(0, min(100, round($v)));
    $ef = fn($x) => $x['anchoBanda'] / $x['consumo'];
    $mejor = max(array_map($ef, inf_productos()));
    $eco = ['CUDA' => 100, 'Metal' => 70, 'ROCm' => 60, 'oneAPI' => 45];
    return [
        'VRAM' => $clamp(log($p['vram'] / 4, 2) / log(128, 2) * 100),
        'Ancho de banda' => $clamp($p['anchoBanda'] / 1792 * 100),
        'Eficiencia' => $clamp($ef($p) / $mejor * 100),
        'Ecosistema' => $eco[$p['ecosistema']] ?? 40,
        'Bajo consumo' => $clamp(100 - $p['consumo'] / 6),
    ];
}
/** Nota IA: media de VRAM, ancho de banda y ecosistema. */
function inf_nota($p) {
    $s = inf_puntuaciones($p);
    return (int) round(($s['VRAM'] + $s['Ancho de banda'] + $s['Ecosistema']) / 3);
}
function inf_mayor_modelo($p) {
    $ult = null;
    foreach (inf_modelos() as $m) { if ($m['tipo'] === 'texto' && inf_cabe($m, $p['vram'])) $ult = $m; }
    return $ult;
}
function inf_gb($n) { return number_format($n, $n < 10 ? 1 : 0, ',', '.') . ' GB'; }
function inf_fecha($iso) { return date('d/m/Y', strtotime($iso)); }

/* ---------- Schema ---------- */

function inf_schema_migas($migas) {
    $items = [];
    foreach ($migas as $i => $m) $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $m[1], 'item' => home_url($m[0])];
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}
function inf_schema_faq($faq) {
    $items = [];
    foreach ($faq as $f) $items[] = ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]];
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items];
}
function inf_schema_lista($productos) {
    $items = [];
    foreach (array_values($productos) as $i => $p) $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => home_url(inf_ficha_path($p)), 'name' => $p['nombre']];
    return ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items];
}

/* ---------- Piezas ---------- */

function inf_e($s) { return esc_html($s); }

function inf_btn_amazon($p, $texto = 'Ver precio en Amazon', $small = false) {
    return '<a class="btn btn-copper' . ($small ? ' btn-sm' : '') . '" href="' . esc_url(inf_amazon_url($p)) . '" data-prod="' . esc_attr($p['nombre']) . '" target="_blank" rel="' . INF_REL . '">' . inf_e($texto)
        . ' <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M9 7h8v8"/></svg></a>';
}

/** Foto de producto alojada en wp-content/inferium/img/p/<ASIN>.webp (imagen principal de Amazon, con permiso de Ernesto). */
function inf_foto_url($asin) {
    if (!$asin || !preg_match('/^[A-Z0-9]{10}$/', $asin)) return '';
    $f = '/inferium/img/p/' . $asin . '.webp';
    return is_file(WP_CONTENT_DIR . $f) ? content_url($f) . '?v=' . filemtime(WP_CONTENT_DIR . $f) : '';
}
function inf_foto_prod($x, $grande = false, $lazy = true) {
    $u = inf_foto_url($x['asin'] ?? '');
    if (!$u) return '';
    return '<div class="foto-prod' . ($grande ? ' grande' : '') . '"><img src="' . esc_url($u) . '" alt="' . esc_attr($x['nombre']) . '" width="600" height="600"' . ($lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"') . '></div>';
}
/** Foto de ambiente (Unsplash o Pexels) alojada en wp-content/inferium/img/a/, con su crédito. Datos en data/fotos.json. */
function inf_fotos() { return inf_datos('fotos'); }
function inf_foto_amb($clave, $uso) {
    foreach (inf_fotos() as $f) { if ($f['clave'] === $clave && $f['uso'] === $uso && is_file(WP_CONTENT_DIR . '/inferium/img/a/' . $f['archivo'])) return $f; }
    return null;
}
function inf_foto_amb_html($f, $clase = '', $lazy = true) {
    if (!$f) return '';
    return '<figure class="foto-amb ' . $clase . ' reveal"><img src="' . esc_url(content_url('/inferium/img/a/' . $f['archivo'])) . '" alt="' . esc_attr($f['alt']) . '" width="' . (int) $f['w'] . '" height="' . (int) $f['h'] . '"' . ($lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"') . '>'
        . '<figcaption class="mini muted">Foto: <a href="' . esc_url($f['pagina']) . '" rel="noopener" target="_blank">' . inf_e($f['autor']) . ' / ' . inf_e($f['sitio']) . '</a></figcaption></figure>';
}

/** Ilustración propia mientras no haya fotos: placa con el chip y la memoria grabada. */
function inf_chip_art($p, $grande = false) {
    $foto = inf_foto_prod($p, $grande, !$grande);
    if ($foto) return $foto;
    static $n = 0; $n++;
    $mods = $p['tipo'] === 'grafica' ? min(12, max(4, (int) round($p['vram'] / 2.7))) : 8;
    $etq = ['grafica' => 'GPU', 'mini-pc' => 'SOC', 'mac' => 'APPLE', 'edge' => 'EDGE'][$p['tipo']] ?? 'GPU';
    $h = '<div class="chip-art' . ($grande ? ' grande' : '') . '" aria-hidden="true"><svg viewBox="0 0 320 200" role="presentation"><defs>'
        . '<linearGradient id="cu' . $n . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ef9459"/><stop offset=".55" stop-color="#c9622c"/><stop offset="1" stop-color="#7a3514"/></linearGradient>'
        . '<linearGradient id="die' . $n . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2a2a2a"/><stop offset="1" stop-color="#151515"/></linearGradient></defs>'
        . '<g stroke="#c9622c" stroke-opacity=".28" stroke-width="1.2" fill="none">';
    for ($i = 0; $i < $mods; $i++) $h .= '<path d="M' . (40 + ($i % 6) * 48) . ' ' . ($i < 6 ? 30 : 170) . ' V ' . ($i < 6 ? 62 : 138) . '"/>';
    $h .= '<path d="M20 100 H90 M230 100 H300 M160 10 V50 M160 150 V190"/></g>';
    for ($i = 0; $i < $mods; $i++) $h .= '<rect x="' . (24 + ($i % 6) * 48) . '" y="' . ($i < 6 ? 14 : 168) . '" width="32" height="18" rx="3" fill="#1e1e1e" stroke="#3a3a3a"/>';
    $h .= '<rect x="90" y="50" width="140" height="100" rx="14" fill="url(#cu' . $n . ')"/>'
        . '<rect x="102" y="60" width="116" height="80" rx="9" fill="url(#die' . $n . ')" stroke="#000" stroke-opacity=".6"/>'
        . '<text x="160" y="108" text-anchor="middle" font-family="Space Grotesk, sans-serif" font-weight="700" font-size="34" fill="#f2efe9">' . (int) $p['vram'] . '<tspan font-size="15" fill="#e98a4f" dx="3">GB</tspan></text>'
        . '<text x="160" y="128" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="9" letter-spacing="2" fill="#8d8a85">' . $etq . ' ' . inf_e(strtoupper($p['marca'])) . '</text>'
        . '</svg></div>';
    return $h;
}

function inf_card($p, $pos = 0) {
    $url = esc_url(inf_url(inf_ficha_path($p)));
    $mayor = inf_mayor_modelo($p);
    $lv = log($p['vram'] / 4, 2) / log(128, 2) * 100;
    $h = '<article class="card surface reveal"><div class="top">' . ($pos ? '<span class="pos copper-text">' . sprintf('%02d', $pos) . '</span>' : '<span></span>')
        . '<span class="badge">' . inf_e($p['ecosistema']) . '</span></div>'
        . '<a href="' . $url . '" tabindex="-1" aria-hidden="true">' . inf_chip_art($p) . '</a>'
        . '<h3><a href="' . $url . '">' . inf_e($p['nombre']) . '</a></h3>'
        . '<div class="star"><b>' . (int) $p['vram'] . '</b><span>GB<br>' . inf_e($p['memoria']) . '</span></div>'
        . '<dl class="specs"><dt>VRAM</dt><dd class="meter"><i style="--w:' . round($lv, 1) . '%"></i></dd>'
        . '<dt>Ancho</dt><dd class="meter"><i style="--w:' . round($p['anchoBanda'] / 1792 * 100, 1) . '%"></i></dd></dl>'
        . '<p class="fits mono">' . ($mayor ? 'Hasta <b>' . inf_e($mayor['nombre']) . '</b> en Q4' : 'Modelos pequeños') . ', ' . (int) $p['anchoBanda'] . ' GB/s</p>'
        . '<p class="resumen muted">' . inf_e($p['resumen']) . '</p>'
        . '<div class="acciones"><a class="btn btn-sm" href="' . $url . '">Ver ficha</a>' . inf_btn_amazon($p, 'Ver en Amazon', true) . '</div></article>';
    return $h;
}

function inf_radar($valores) {
    $ejes = array_keys($valores); $n = count($ejes); $c = 130; $r = 92;
    $pt = function ($i, $v) use ($n, $c, $r) { $a = -M_PI / 2 + $i * 2 * M_PI / $n; return [round($c + cos($a) * $r * $v / 100, 1), round($c + sin($a) * $r * $v / 100, 1)]; };
    $poly = function ($f) use ($n, $pt) { $o = []; for ($i = 0; $i < $n; $i++) $o[] = implode(',', $pt($i, $f($i))); return implode(' ', $o); };
    $aria = []; foreach ($valores as $k => $v) $aria[] = "$k $v";
    $h = '<figure class="radar"><svg viewBox="0 0 260 260" role="img" aria-label="' . esc_attr(implode(', ', $aria)) . '">';
    foreach ([25, 50, 75, 100] as $l) $h .= '<polygon points="' . $poly(fn() => $l) . '" fill="none" stroke="#2f2f2f"/>';
    for ($i = 0; $i < $n; $i++) { [$x, $y] = $pt($i, 100); $h .= "<line x1=\"$c\" y1=\"$c\" x2=\"$x\" y2=\"$y\" stroke=\"#2a2a2a\"/>"; }
    $h .= '<polygon points="' . $poly(fn($i) => $valores[$ejes[$i]]) . '" fill="#c9622c" fill-opacity=".28" stroke="#e98a4f" stroke-width="2" style="filter:drop-shadow(0 0 8px rgba(233,138,79,.55))"/>';
    for ($i = 0; $i < $n; $i++) { [$x, $y] = $pt($i, 122); $h .= "<text x=\"$x\" y=\"$y\" text-anchor=\"middle\" dominant-baseline=\"middle\" font-size=\"10\" font-family=\"JetBrains Mono, monospace\" fill=\"#9b9893\">" . inf_e($ejes[$i]) . ' <tspan fill="#ededeb">' . $valores[$ejes[$i]] . '</tspan></text>'; }
    return $h . '</svg></figure>';
}

function inf_migas($migas) {
    $h = '<nav aria-label="Migas de pan" class="migas"><ol>';
    foreach ($migas as $i => $m) $h .= '<li>' . ($i < count($migas) - 1 ? '<a href="' . esc_url(inf_url($m[0])) . '">' . inf_e($m[1]) . '</a>' : '<span aria-current="page">' . inf_e($m[1]) . '</span>') . '</li>';
    return $h . '</ol></nav>';
}

function inf_faq($faq, $titulo = 'Preguntas frecuentes') {
    $h = '<section class="faq"><h2>' . inf_e($titulo) . '</h2><div class="faq-lista">';
    foreach ($faq as $f) $h .= '<details class="surface"><summary>' . inf_e($f[0]) . '</summary><p>' . inf_e($f[1]) . '</p></details>';
    return $h . '</div></section>';
}

function inf_calc() {
    $pasos = [8, 12, 16, 24, 32, 48, 64, 128, 192, 512]; $ini = 3;
    $prods = array_map(fn($p) => ['url' => inf_url(inf_ficha_path($p)), 'nombre' => $p['nombre'], 'vram' => $p['vram'], 'anchoBanda' => $p['anchoBanda']], inf_productos());
    $h = '<div class="calc" data-calc><div class="panel surface"><p class="eyebrow">Memoria de tu GPU</p>'
        . '<output class="vram-big"><span data-v>' . $pasos[$ini] . '</span><small>GB</small></output>'
        . '<label class="sr-only" for="vram-range">VRAM disponible</label><div class="slider well"><input id="vram-range" type="range" min="0" max="' . (count($pasos) - 1) . '" step="1" value="' . $ini . '" data-range></div>'
        . '<div class="ticks mono"><span>8</span><span>24</span><span>64</span><span>192</span><span>512</span></div>'
        . '<div class="chips" role="group" aria-label="Cuantización"><button type="button" class="chip" data-bits="4.5" aria-pressed="true">Q4</button><button type="button" class="chip" data-bits="8.5" aria-pressed="false">Q8</button><button type="button" class="chip" data-bits="16" aria-pressed="false">FP16</button></div>'
        . '<p class="nota muted">Estimación: parámetros × bits ÷ 8, más un 20 % para contexto y caché. Los modelos de imagen usan su consumo típico.</p><p class="reco" data-reco></p></div>'
        . '<div class="panel surface"><ul class="modelos">';
    foreach (inf_modelos() as $m) {
        $n = inf_vram_necesaria($m); $ok = $n <= $pasos[$ini];
        $h .= '<li class="modelo ' . ($ok ? 'si' : 'no') . '"><span class="nombre">' . inf_e($m['nombre']) . ' <small class="muted">' . inf_e($m['tipo']) . '</small></span><span class="tag">' . ($ok ? 'cabe' : 'no cabe') . '</span>'
            . '<span class="meter"><i style="--w:' . round(min(100, $n / $pasos[$ini] * 100), 1) . '%"></i></span><span class="need mono">' . inf_gb($n) . '</span></li>';
    }
    return $h . '</ul></div><script type="application/json" data-datos>' . wp_json_encode(['modelos' => inf_modelos(), 'pasos' => $pasos, 'productos' => $prods]) . '</script></div>';
}

/* ---------- Documento ---------- */

function inf_nav() {
    return [['/tarjetas-graficas/', 'GPU para IA'], ['/mini-pc/', 'Mini PCs'], ['/mac/', 'Mac'], ['/guias/', 'Guías'], ['/comparador/', 'Comparador'], ['/calculadora-vram/', 'Calculadora VRAM']];
}

function inf_documento($a) {
    $a += ['imagen' => '', 'title' => INF_NOMBRE, 'description' => '', 'path' => '/', 'schema' => [], 'body' => '', 'clase' => '', 'borrador' => false, 'og' => 'website', 'simple' => false];
    $solo_legal = !empty($a['noindex']);
    $noindex = $a['borrador'] || !get_option('blog_public') || $solo_legal;
    $canon = home_url($a['path']);
    $org = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => INF_NOMBRE, 'url' => home_url('/'), 'founder' => ['@type' => 'Person', 'name' => INF_AUTOR]];
    $logo = '<a class="logo" href="' . esc_url(inf_url('/')) . '" aria-label="' . INF_NOMBRE . ', inicio"><span class="logo-mark" aria-hidden="true"></span>' . INF_NOMBRE . '</a>';
    $h = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . inf_e($a['title']) . '</title><meta name="description" content="' . esc_attr($a['description']) . '">'
        . '<link rel="canonical" href="' . esc_url($canon) . '">'
        . '<meta name="robots" content="' . ($noindex ? ($solo_legal && !$a['borrador'] && get_option('blog_public') ? 'noindex, follow' : 'noindex, nofollow') : 'index, follow, max-image-preview:large') . '">'
        . '<meta property="og:type" content="' . esc_attr($a['og']) . '"><meta property="og:title" content="' . esc_attr($a['title']) . '"><meta property="og:description" content="' . esc_attr($a['description']) . '"><meta property="og:url" content="' . esc_url($canon) . '"><meta property="og:locale" content="es_ES">' . (!empty($a['imagen']) ? '<meta property="og:image" content="' . esc_url($a['imagen']) . '">' : '') . '<meta property="og:site_name" content="' . INF_NOMBRE . '">'
        . '<meta name="twitter:card" content="summary_large_image"><meta name="theme-color" content="#141414">'
        . '<link rel="icon" href="' . esc_url(inf_asset('favicon.svg')) . '" type="image/svg+xml">'
        . '<link rel="preload" href="' . esc_url(content_url('inferium/assets/fonts/space-grotesk.woff2')) . '" as="font" type="font/woff2" crossorigin>'
        . '<link rel="stylesheet" href="' . esc_url(inf_asset('inferium.css')) . '">'
        . '<script>document.documentElement.classList.add("js")</script>';
    // Medición: códigos en las opciones inferium_gsc (Search Console) e inferium_ga4 (Analytics). Analytics solo carga con consentimiento y nunca en borradores.
    $gsc = preg_replace('/[^A-Za-z0-9_-]/', '', (string) get_option('inferium_gsc', ''));
    $ga4 = (string) get_option('inferium_ga4', ''); if (!preg_match('/^G-[A-Z0-9]{4,}$/', $ga4)) $ga4 = '';
    if ($gsc) $h .= '<meta name="google-site-verification" content="' . esc_attr($gsc) . '">';
    $clar = preg_replace('/[^a-z0-9]/', '', (string) get_option('inferium_clarity', ''));
    $cookies = '';
    if (($ga4 || $clar) && !$a['borrador']) {
        $cookies = '<div class="cookies surface" data-cookies hidden role="dialog" aria-label="Aviso de cookies"><p>Usamos cookies de medición solo para saber qué se visita y mejorar la web. <a href="' . esc_url(inf_url('/cookies/')) . '">Más información</a>.</p><div class="cookies-btns"><button class="btn btn-sm" type="button" data-cookies-no>Rechazar</button><button class="btn btn-sm btn-copper" type="button" data-cookies-si>Aceptar</button></div></div>'
            . '<script>(function(){var K="inf_cookies",id="' . $ga4 . '",cl="' . $clar . '",b=document.querySelector("[data-cookies]");function get(){try{return localStorage.getItem(K)}catch(e){return null}}function set(v){try{localStorage.setItem(K,v)}catch(e){}}'
            . 'function ga(){if(cl){(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y)})(window,document,"clarity","script",cl)}if(!id)return;var s=document.createElement("script");s.async=true;s.src="https://www.googletagmanager.com/gtag/js?id="+id;document.head.appendChild(s);window.dataLayer=window.dataLayer||[];window.gtag=function(){dataLayer.push(arguments)};gtag("js",new Date());gtag("config",id,{anonymize_ip:true})}'
            . 'var v=get();if(v==="si")ga();else if(v!=="no"&&b)b.hidden=false;if(b){b.querySelector("[data-cookies-si]").onclick=function(){set("si");b.hidden=true;ga()};b.querySelector("[data-cookies-no]").onclick=function(){set("no");b.hidden=true}}'
            . 'var r=document.querySelector("[data-cookies-reset]");if(r)r.onclick=function(){try{localStorage.removeItem(K)}catch(e){}location.reload()}})();</script>';
    }
    foreach (array_merge([$org], $a['schema']) as $s) $h .= '<script type="application/ld+json">' . wp_json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    $h .= '</head><body class="' . esc_attr($a['clase']) . '">';
    if ($a['borrador']) $h .= '<div class="borrador">Borrador: solo lo ves tú con la sesión iniciada. Aún no es público.</div>';
    if ($a['simple']) return $h . $a['body'] . $cookies . '<script src="' . esc_url(inf_asset('inferium.js')) . '" defer></script></body></html>';
    $h .= '<header class="topbar"><div class="wrap bar">' . $logo . '<nav aria-label="Principal"><ul class="tabs">';
    foreach (inf_nav() as $n) $h .= '<li><a href="' . esc_url(inf_url($n[0])) . '"' . ($a['path'] === $n[0] ? ' aria-current="page"' : '') . '>' . $n[1] . '</a></li>';
    $h .= '</ul></nav><details class="menu-movil"><summary class="btn btn-sm">Menú</summary><ul class="surface">';
    foreach (inf_nav() as $n) $h .= '<li><a href="' . esc_url(inf_url($n[0])) . '">' . $n[1] . '</a></li>';
    foreach (inf_categorias() as $c) { if (!in_array('/' . $c['slug'] . '/', array_column(inf_nav(), 0), true)) $h .= '<li><a href="' . esc_url(inf_url('/' . $c['slug'] . '/')) . '">' . inf_e($c['nombre']) . '</a></li>'; }
    $h .= '</ul></details></div></header>' . $a['body'];
    $h .= '<footer class="pie"><div class="wrap pie-grid"><div>' . $logo . '<p class="muted pie-txt">Hardware para IA local elegido por VRAM, ancho de banda y lo que de verdad cabe. Sin precios copiados: siempre el actual en Amazon.</p></div><div><p class="pie-tit">Categorías</p><ul>';
    foreach (inf_categorias() as $c) $h .= '<li><a href="' . esc_url(inf_url('/' . $c['slug'] . '/')) . '">' . inf_e($c['nombre']) . '</a></li>';
    $h .= '<li><a href="' . esc_url(inf_url('/guias/')) . '">Guías</a></li><li><a href="' . esc_url(inf_url('/portatiles-para-ia/')) . '">Portátiles para IA</a></li><li><a href="' . esc_url(inf_url('/componentes/')) . '">Componentes</a></li><li><a href="' . esc_url(inf_url('/hardware/')) . '">Todo el catálogo</a></li></ul></div><div><p class="pie-tit">Herramientas</p><ul><li><a href="' . esc_url(inf_url('/comparador/')) . '">Comparador</a></li><li><a href="' . esc_url(inf_url('/calculadora-vram/')) . '">Calculadora de VRAM</a></li></ul>'
        . '<p class="pie-tit pie-tit2">Confianza</p><ul><li><a href="' . esc_url(inf_url('/como-elegimos/')) . '">Cómo elegimos</a></li><li><a href="' . esc_url(inf_url('/aviso-afiliacion/')) . '">Aviso de afiliación</a></li><li><a href="' . esc_url(inf_url('/aviso-legal/')) . '">Aviso legal</a></li><li><a href="' . esc_url(inf_url('/politica-de-privacidad/')) . '">Política de privacidad</a></li><li><a href="' . esc_url(inf_url('/cookies/')) . '">Cookies</a></li></ul></div></div>'
        . '<div class="wrap legal">En calidad de Afiliado de Amazon, ' . INF_NOMBRE . ' obtiene ingresos por las compras adscritas que cumplen los requisitos aplicables. Los precios cambian a diario: consulta siempre el actual en Amazon.</div></footer>' . $cookies
        . '<script src="' . esc_url(inf_asset('inferium.js')) . '" defer></script></body></html>';
    return $h;
}

/* ---------- Páginas ---------- */

function inf_pag_inicio() {
    $prods = inf_productos();
    usort($prods, fn($a, $b) => inf_nota($b) <=> inf_nota($a));
    $dest = array_slice($prods, 0, 6);
    $faq = [
        ['¿Qué es lo más importante en una GPU para IA?', 'La VRAM: decide qué modelos caben. Después, el ancho de banda de memoria, que marca cuántos tokens por segundo genera. Los FPS en juegos no dicen nada sobre IA.'],
        ['¿Por qué no mostráis precios?', 'Porque en Amazon cambian varias veces al día. Preferimos llevarte al precio real en Amazon antes que enseñarte uno viejo.'],
        ['¿Cómo gana dinero ' . INF_NOMBRE . '?', 'Con enlaces de afiliado de Amazon: si compras desde ellos, Amazon nos paga una pequeña comisión sin que tú pagues más. No cobramos a marcas por aparecer.'],
        ['¿De dónde salen los tokens por segundo?', 'Son una estimación propia a partir del ancho de banda y el tamaño del modelo, no un benchmark. La fórmula está publicada en "Cómo elegimos".'],
    ];
    $b = '<header class="hero"><canvas id="die" aria-hidden="true"></canvas><div class="wrap hero-in">'
        . '<h1><span class="neon">Corre la IA</span> en tu propia máquina</h1>'
        . '<p class="lede">GPU, mini PCs y Mac elegidos por lo que importa para la IA: <strong>VRAM, ancho de banda y qué modelo te cabe</strong>. Nada de FPS en juegos.</p>'
        . '<div class="ctas"><a class="btn btn-copper" href="' . esc_url(inf_url('/calculadora-vram/')) . '">¿Qué modelo cabe en mi GPU?</a><a class="btn" href="' . esc_url(inf_url('/comparador/')) . '">Comparar GPU</a></div>'
        . '</div></header><main>';
    $b .= '<section class="block"><div class="wrap"><div class="sec-head"><h2>Elige por lo que quieres hacer</h2></div><div class="cats">';
    foreach (inf_categorias() as $i => $c) {
        $n = count(inf_de_categoria($c));
        $b .= '<a class="cat surface reveal' . ($i === 0 ? ' cat-principal' : '') . '" href="' . esc_url(inf_url('/' . $c['slug'] . '/')) . '"><h3>' . inf_e($c['nombre']) . '</h3><p class="muted">' . inf_e(explode('. ', $c['intro'])[0]) . '.</p><span class="cuenta mono">' . $n . ' equipos</span></a>';
    }
    $b .= '</div></div></section>';
    $gs = array_slice(inf_guias(), 0, 4);
    $b .= '<section class="block sin-top"><div class="wrap split-foto">' . inf_foto_amb_html(inf_foto_amb('portada', 'hero'), '') . '<div><h2>Tu propia IA, en tu escritorio</h2><p class="muted">Sin cuotas, sin límites de mensajes y sin mandar tus datos a ninguna nube. Con la tarjeta gráfica o el ordenador adecuado, modelos como Llama, Qwen o Mistral funcionan en casa. Empieza por estas guías:</p><ul class="lista enlaces">';
    foreach ($gs as $g) $b .= '<li><a href="' . esc_url(inf_url('/guias/' . $g['slug'] . '/')) . '">' . inf_e($g['titulo']) . '</a></li>';
    $b .= '</ul><a class="btn btn-sm" href="' . esc_url(inf_url('/guias/')) . '">Ver todas las guías</a></div></div></section>';
    $b .= '<section class="block sin-top" id="calc"><div class="wrap"><div class="sec-head"><h2>Calculadora de VRAM</h2><p>Mueve la memoria y mira qué modelos te caben. Te decimos el equipo más ajustado que lo cumple.</p></div>' . inf_calc() . '</div></section>';
    $b .= '<section class="block sin-top"><div class="wrap"><div class="sec-head"><h2>Los más completos para IA</h2><p>Ordenados por lo que más pesa en IA: VRAM, ancho de banda y ecosistema de software. <a href="' . esc_url(inf_url('/como-elegimos/')) . '">Así puntuamos</a>.</p></div><div class="grid-cards">';
    foreach ($dest as $i => $p) $b .= inf_card($p, $i + 1);
    $b .= '</div></div></section>';
    $b .= '<section class="block sin-top"><div class="wrap pasos-wrap"><div><h2>Cómo elegir en cinco pasos</h2><ol class="pasos">'
        . '<li><div><b>Decide qué modelo quieres correr.</b> Un chatbot de 8B, uno de 70B o generar imágenes con Flux piden máquinas muy distintas.</div></li>'
        . '<li><div><b>Calcula la VRAM.</b> Usa la <a href="' . esc_url(inf_url('/calculadora-vram/')) . '">calculadora</a>: en Q4, un modelo ocupa en GB algo más de la mitad de sus miles de millones de parámetros.</div></li>'
        . '<li><div><b>Mira el ancho de banda.</b> Con la misma memoria, más GB/s es más velocidad al generar texto.</div></li>'
        . '<li><div><b>Elige ecosistema.</b> CUDA (NVIDIA) es lo más compatible; AMD da más memoria por euro; Mac, silencio y mucha memoria.</div></li>'
        . '<li><div><b>Revisa "lo que no".</b> Cada ficha te dice qué no hace bien: fuente necesaria, tamaño, software. Léelo antes de comprar.</div></li>'
        . '</ol></div>' . inf_faq($faq) . '</div></section></main>';
    return [
        'title' => INF_NOMBRE . ' | GPU, tarjetas gráficas y ordenadores para IA local',
        'description' => 'Comparativa de GPU (tarjetas gráficas), mini PCs y Mac para IA local: VRAM, ancho de banda, modelos que caben y lo que no te cuentan. Calculadora de VRAM y comparador.',
        'path' => '/', 'clase' => 'p-inicio', 'body' => $b,
        'schema' => [['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => INF_NOMBRE, 'url' => home_url('/'), 'inLanguage' => 'es-ES'], inf_schema_lista($dest), inf_schema_faq($faq)],
    ];
}

function inf_pag_categoria($slug) {
    $cat = inf_categoria($slug);
    if (!$cat) return null;
    $lista = inf_de_categoria($cat);
    usort($lista, fn($a, $b) => [$b['vram'], $b['anchoBanda']] <=> [$a['vram'], $a['anchoBanda']]);
    $migas = [['/', 'Inicio'], ["/$slug/", $cat['nombre']]];
    $rev = max(array_column($lista, 'revisado'));
    $b = '<main class="wrap"><section class="cabecera">' . inf_migas($migas) . '<h1>' . inf_e($cat['titulo']) . '</h1><p class="intro">' . inf_e($cat['intro']) . '</p>'
        . '<div class="meta"><span class="badge">' . count($lista) . ' equipos</span><span class="badge">Datos revisados el ' . inf_fecha($rev) . '</span><a class="badge badge-copper" href="' . esc_url(inf_url('/comparador/')) . '">Comparar</a></div></section>' . inf_foto_amb_html(inf_foto_amb($slug, 'hero'), 'sec-portada', false) . '<div class="grid-cards">';
    foreach ($lista as $i => $p) $b .= inf_card($p, $i + 1);
    $b .= '</div><section class="block">' . inf_faq($cat['faq']) . '</section><section class="otras"><h2>Otras categorías</h2><div class="chips">';
    foreach (inf_categorias() as $c) { if ($c['slug'] !== $slug) $b .= '<a class="chip" href="' . esc_url(inf_url('/' . $c['slug'] . '/')) . '">' . inf_e($c['nombre']) . '</a>'; }
    $b .= '</div></section><p class="aviso muted">Enlaces de afiliado de Amazon: si compras desde ellos recibimos una comisión sin coste para ti. No mostramos precios porque cambian a diario.</p></main>';
    return ['title' => $cat['titulo'] . ' | ' . INF_NOMBRE, 'description' => $cat['descripcion'], 'path' => "/$slug/", 'clase' => 'p-categoria', 'body' => $b,
        'schema' => [inf_schema_migas($migas), inf_schema_lista($lista), inf_schema_faq($cat['faq'])]];
}

function inf_pag_catalogo() {
    $lista = inf_productos();
    usort($lista, fn($a, $b) => inf_nota($b) <=> inf_nota($a));
    $migas = [['/', 'Inicio'], ['/hardware/', 'Catálogo']];
    $b = '<main class="wrap"><section class="cabecera">' . inf_migas($migas) . '<h1>Todo el hardware para IA</h1><p class="intro">Todas las gráficas, mini PCs y equipos que hemos analizado, ordenados por nuestra nota IA.</p></section><div class="grid-cards">';
    foreach ($lista as $i => $p) $b .= inf_card($p, $i + 1);
    $b .= '</div><p class="aviso muted">Enlaces de afiliado de Amazon: si compras desde ellos recibimos una comisión sin coste para ti.</p></main>';
    return ['title' => 'Catálogo de hardware para IA | ' . INF_NOMBRE, 'description' => 'Todas las tarjetas gráficas, mini PCs y Mac para IA local analizados en ' . INF_NOMBRE . ', ordenados por nota.', 'path' => '/hardware/', 'clase' => 'p-categoria', 'body' => $b,
        'schema' => [inf_schema_migas($migas), inf_schema_lista($lista)]];
}

function inf_pag_ficha($slug) {
    $p = inf_producto($slug);
    if (!$p) return null;
    $cat = inf_cat_tipo($p);
    $path = inf_ficha_path($p);
    $migas = [['/', 'Inicio'], ['/' . $cat['slug'] . '/', $cat['nombre']], [$path, $p['nombre']]];
    $radar = inf_puntuaciones($p); $nota = inf_nota($p);
    $tipo = ['grafica' => 'Tarjeta gráfica (GPU)', 'mini-pc' => 'Mini PC', 'mac' => 'Mac', 'edge' => 'Placa de IA'][$p['tipo']] ?? 'Equipo';
    $b = '<main class="wrap"><div class="migas-top">' . inf_migas($migas) . '</div><section class="ficha"><div class="visual">' . inf_chip_art($p, true) . '</div><div class="datos">'
        . '<span class="badge badge-copper">' . $tipo . ', ' . inf_e($p['ecosistema']) . '</span><h1>' . inf_e($p['nombre']) . '</h1><p class="resumen">' . inf_e($p['resumen']) . '</p>'
        . '<div class="estrella surface"><div><span class="eyebrow">Memoria para IA</span><div class="gb"><b>' . (int) $p['vram'] . '</b><span>GB ' . inf_e($p['memoria']) . '</span></div></div><div class="nota"><span class="eyebrow">Nota IA</span><b class="copper-text">' . $nota . '</b></div></div>'
        . inf_btn_amazon($p) . '<p class="mini muted">Precio y disponibilidad en Amazon. Cambian a diario.</p></div></section>';
    $b .= '<section class="bloques"><div class="surface pad"><h2>Puntuación</h2>' . inf_radar($radar) . '<p class="mini muted">Fórmulas publicadas en <a href="' . esc_url(inf_url('/como-elegimos/')) . '">Cómo elegimos</a>.</p></div>'
        . '<div class="surface pad"><h2>Modelos que caben</h2><p class="mini muted">En Q4. Tokens/s: estimación propia a partir del ancho de banda, no benchmark verificado.</p><div class="tabla-scroll"><table class="modelos"><thead><tr><th>Modelo</th><th>Necesita</th><th>¿Cabe?</th><th>Tokens/s</th></tr></thead><tbody>';
    foreach (inf_modelos() as $m) {
        $ok = inf_cabe($m, $p['vram']); $tps = inf_tokens($p, $m);
        $b .= '<tr class="' . ($ok ? 'si' : 'no') . '"><td>' . inf_e($m['nombre']) . '</td><td class="mono">' . inf_gb(inf_vram_necesaria($m)) . '</td><td><span class="badge' . ($ok ? ' badge-ok' : '') . '">' . ($ok ? 'Sí' : 'No') . '</span></td><td class="mono">' . ($tps ? '≈ ' . $tps : ($m['tipo'] === 'imagen' && $ok ? 'imagen' : '-')) . '</td></tr>';
    }
    $b .= '</tbody></table></div></div></section><section class="bloques tres"><div class="surface pad"><h2 class="h-ok">Lo bueno</h2><ul class="lista">';
    foreach ($p['pros'] as $x) $b .= '<li>' . inf_e($x) . '</li>';
    $b .= '</ul></div><div class="surface pad"><h2 class="h-no">Lo que no</h2><ul class="lista no">';
    foreach ($p['loQueNo'] as $x) $b .= '<li>' . inf_e($x) . '</li>';
    $b .= '</ul></div><div class="surface pad para"><h2>Para quién es</h2><p>' . inf_e($p['paraQuien']) . '</p>' . inf_btn_amazon($p, 'Ver en Amazon', true) . '</div></section>';
    $b .= '<section class="surface pad specs-wrap"><h2>Especificaciones</h2><dl class="specs">'
        . '<div><dt>Marca</dt><dd>' . inf_e($p['marca']) . '</dd></div><div><dt>Memoria</dt><dd>' . (int) $p['vram'] . ' GB ' . inf_e($p['memoria']) . '</dd></div>'
        . '<div><dt>Ancho de banda</dt><dd>' . (int) $p['anchoBanda'] . ' GB/s</dd></div><div><dt>Consumo</dt><dd>' . (int) $p['consumo'] . ' W' . ($p['tipo'] !== 'grafica' ? ' (equipo completo)' : '') . '</dd></div>'
        . ($p['fuente'] > 0 ? '<div><dt>Fuente recomendada</dt><dd>' . (int) $p['fuente'] . ' W</dd></div>' : '')
        . '<div><dt>Software de IA</dt><dd>' . inf_e($p['ecosistema']) . '</dd></div><div><dt>Lanzamiento</dt><dd>' . (int) $p['lanzamiento'] . '</dd></div><div><dt>Datos revisados</dt><dd>' . inf_fecha($p['revisado']) . '</dd></div></dl></section>';
    $b .= inf_cruzada($p);
    $vs = array_values(array_filter(inf_versus(), fn($v) => $v['a'] === $slug || $v['b'] === $slug));
    if ($vs) {
        $b .= '<section class="surface pad"><h2>Comparativas</h2><ul class="lista enlaces">';
        foreach ($vs as $v) { $pa = inf_producto($v['a']); $pb = inf_producto($v['b']); if ($pa && $pb) $b .= '<li><a href="' . esc_url(inf_url('/comparador/' . $v['slug'] . '/')) . '">' . inf_e(inf_corto($pa) . ' vs ' . inf_corto($pb)) . ': cuál comprar</a></li>'; }
        $b .= '</ul></section>';
    }
    $rel = array_slice(array_values(array_filter(inf_productos(), fn($x) => $x['slug'] !== $slug && $x['tipo'] === $p['tipo'])), 0, 3);
    if ($rel) { $b .= '<section class="block"><div class="sec-head"><h2>Si no te encaja, mira estos</h2></div><div class="grid-cards">'; foreach ($rel as $r) $b .= inf_card($r); $b .= '</div></section>'; }
    $b .= '<p class="mini muted">Enlace de afiliado de Amazon: si compras desde él recibimos una comisión sin coste para ti. Datos revisados el ' . inf_fecha($p['revisado']) . '.</p></main>';
    $prod = ['@context' => 'https://schema.org', '@type' => 'Product', 'image' => inf_foto_url($p['asin'] ?? '') ?: null, 'name' => $p['nombre'], 'brand' => ['@type' => 'Brand', 'name' => $p['marca']], 'description' => $p['resumen'], 'url' => home_url($path),
        'review' => ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => INF_AUTOR], 'datePublished' => $p['revisado'], 'reviewBody' => $p['paraQuien'], 'reviewRating' => ['@type' => 'Rating', 'ratingValue' => $nota, 'bestRating' => 100, 'worstRating' => 0]]];
    if (empty($prod['image'])) unset($prod['image']);
    return ['title' => inf_corto($p) . ': precio, opiniones y qué IA mueve' . ($p['tipo'] === 'grafica' ? ' esta GPU | ' : ' | ') . INF_NOMBRE,
        'description' => $p['nombre'] . ' para IA local: ' . $p['vram'] . ' GB de ' . $p['memoria'] . ', ' . $p['anchoBanda'] . ' GB/s. Qué modelos caben, tokens por segundo estimados y lo que no hace bien.',
        'path' => $path, 'clase' => 'p-ficha', 'og' => 'product', 'imagen' => inf_foto_url($p['asin'] ?? ''), 'body' => $b, 'schema' => [inf_schema_migas($migas), $prod]];
}

function inf_pag_comparador() {
    $migas = [['/', 'Inicio'], ['/comparador/', 'Comparador']];
    $llama8 = null; foreach (inf_modelos() as $m) { if ($m['params'] == 8) $llama8 = $m; }
    $nmod = fn($p) => count(array_filter(inf_modelos(), fn($m) => $m['tipo'] === 'texto' && inf_cabe($m, $p['vram'])));
    $meta = [['VRAM', 'max'], ['Tipo de memoria', null], ['Ancho de banda', 'max'], ['Tokens/s Llama 8B (est.)', 'max'], ['Modelo más grande en Q4', 'max'], ['Consumo', 'min'], ['Software de IA', null], ['Fuente recomendada', null], ['Nota IA', 'max']];
    $datos = [];
    foreach (inf_productos() as $p) {
        $t = inf_tokens($p, $llama8); $may = inf_mayor_modelo($p);
        $datos[] = ['slug' => $p['slug'], 'nombre' => $p['nombre'], 'url' => inf_amazon_url($p), 'ficha' => inf_url(inf_ficha_path($p)), 'celdas' => [
            ['n' => $p['vram'], 't' => $p['vram'] . ' GB'], ['n' => null, 't' => $p['memoria']], ['n' => $p['anchoBanda'], 't' => $p['anchoBanda'] . ' GB/s'],
            ['n' => $t ?? 0, 't' => $t ? '≈ ' . $t : 'no cabe'], ['n' => $nmod($p), 't' => $may ? $may['nombre'] : 'ninguno'],
            ['n' => $p['consumo'], 't' => $p['consumo'] . ' W'], ['n' => null, 't' => $p['ecosistema']], ['n' => null, 't' => $p['fuente'] ? $p['fuente'] . ' W' : 'Incluida'], ['n' => inf_nota($p), 't' => (string) inf_nota($p)],
        ]];
    }
    $ini = ['nvidia-rtx-5060-ti-16gb', 'amd-radeon-rx-9070-xt', 'nvidia-rtx-5080', 'apple-mac-mini-m6'];
    $b = '<main class="wrap"><section class="cabecera">' . inf_migas($migas) . '<h1>Comparador de GPU y hardware para IA</h1><p class="muted intro">Elige hasta cuatro. En cobre, el mejor valor de cada fila.</p></section><div class="selectores">';
    for ($i = 0; $i < 4; $i++) {
        $b .= '<label class="well sel"><span class="sr-only">Equipo ' . ($i + 1) . '</span><select data-sel="' . $i . '"><option value="">Ninguno</option>';
        foreach (inf_productos() as $p) $b .= '<option value="' . esc_attr($p['slug']) . '"' . ($p['slug'] === $ini[$i] ? ' selected' : '') . '>' . inf_e($p['nombre']) . '</option>';
        $b .= '</select></label>';
    }
    $b .= '</div><div class="tabla-wrap surface"><table class="tabla" data-tabla><thead><tr><th scope="col">Dato</th><th scope="col"></th><th scope="col"></th><th scope="col"></th><th scope="col"></th></tr></thead><tbody>';
    foreach ($meta as $f) $b .= '<tr><th scope="row">' . inf_e($f[0]) . '</th><td></td><td></td><td></td><td></td></tr>';
    $b .= '<tr class="comprar"><th scope="row">Precio</th><td></td><td></td><td></td><td></td></tr></tbody></table></div>'
        . '<noscript><p class="muted">El comparador necesita JavaScript. Puedes ver todas las fichas desde las categorías.</p></noscript>'
        . '<p class="muted mini">Tokens/s: estimación propia a partir del ancho de banda (ver <a href="' . esc_url(inf_url('/como-elegimos/')) . '">Cómo elegimos</a>). Enlaces de afiliado de Amazon.</p>'
        . '<script type="application/json" data-comparador>' . wp_json_encode(['datos' => $datos, 'meta' => $meta, 'rel' => INF_REL]) . '</script></main>';
    return ['title' => 'Comparador de GPU y tarjetas gráficas para IA | ' . INF_NOMBRE, 'description' => 'Compara hasta 4 GPU (tarjetas gráficas), mini PCs o Mac para IA: VRAM, ancho de banda, tokens por segundo estimados, consumo y modelo más grande que cabe.',
        'path' => '/comparador/', 'clase' => 'p-comparador', 'body' => $b, 'schema' => [inf_schema_migas($migas)]];
}

function inf_pag_versus($slug) {
    $v = null; foreach (inf_versus() as $x) { if ($x['slug'] === $slug) $v = $x; }
    if (!$v) return null;
    $a = inf_producto($v['a']); $b2 = inf_producto($v['b']);
    if (!$a || !$b2) return null;
    $na = inf_corto($a); $nb = inf_corto($b2); $path = '/comparador/' . $slug . '/';
    $migas = [['/', 'Inicio'], ['/comparador/', 'Comparador'], [$path, $na . ' vs ' . $nb]];
    $llama8 = null; foreach (inf_modelos() as $m) { if ($m['params'] == 8) $llama8 = $m; }
    $ta = inf_tokens($a, $llama8); $tb = inf_tokens($b2, $llama8);
    $ma = inf_mayor_modelo($a); $mb = inf_mayor_modelo($b2);
    $nta = inf_nota($a); $ntb = inf_nota($b2);
    $esGpu = $a['tipo'] === 'grafica' && $b2['tipo'] === 'grafica';
    $filas = [
        ['Memoria para IA (VRAM)', $a['vram'], $b2['vram'], $a['vram'] . ' GB', $b2['vram'] . ' GB', 'max'],
        ['Tipo de memoria', null, null, $a['memoria'], $b2['memoria'], null],
        ['Ancho de banda', $a['anchoBanda'], $b2['anchoBanda'], $a['anchoBanda'] . ' GB/s', $b2['anchoBanda'] . ' GB/s', 'max'],
        ['Tokens/s Llama 8B (est.)', $ta ?? 0, $tb ?? 0, $ta ? '≈ ' . $ta : 'no cabe', $tb ? '≈ ' . $tb : 'no cabe', 'max'],
        ['Modelo más grande en Q4', $ma ? $ma['params'] : 0, $mb ? $mb['params'] : 0, $ma ? $ma['nombre'] : 'ninguno', $mb ? $mb['nombre'] : 'ninguno', 'max'],
        ['Consumo', $a['consumo'], $b2['consumo'], $a['consumo'] . ' W', $b2['consumo'] . ' W', 'min'],
        ['Software de IA', null, null, $a['ecosistema'], $b2['ecosistema'], null],
        ['Nota IA', $nta, $ntb, (string) $nta, (string) $ntb, 'max'],
    ];
    // Veredicto en frases, a partir de los datos
    $v1 = [];
    if ($a['vram'] !== $b2['vram']) {
        $g = $a['vram'] > $b2['vram'] ? [$na, $a, $nb, $b2, $ma] : [$nb, $b2, $na, $a, $mb];
        $v1[] = 'La ' . $g[0] . ' tiene ' . $g[1]['vram'] . ' GB de memoria frente a los ' . $g[3]['vram'] . ' GB de la ' . $g[2] . '. En IA eso manda: decide qué modelos caben' . ($g[4] && (!$ma || !$mb || $ma['params'] !== $mb['params']) ? ', y con ella llegas hasta ' . $g[4]['nombre'] . ' en Q4.' : ' y cuánto contexto les queda.');
    } else {
        $v1[] = 'Las dos tienen ' . $a['vram'] . ' GB de memoria, así que cargan exactamente los mismos modelos de IA. La diferencia está en la velocidad y en el software.';
    }
    if ($a['anchoBanda'] !== $b2['anchoBanda']) {
        $g = $a['anchoBanda'] > $b2['anchoBanda'] ? [$na, $a, $nb, $b2] : [$nb, $b2, $na, $a];
        $v1[] = 'En velocidad gana la ' . $g[0] . ': ' . $g[1]['anchoBanda'] . ' GB/s de ancho de banda frente a ' . $g[3]['anchoBanda'] . ' GB/s, lo que se traduce en respuestas más rápidas con el mismo modelo.';
    } else {
        $v1[] = 'En velocidad de memoria van igual: ' . $a['anchoBanda'] . ' GB/s las dos.';
    }
    if ($a['ecosistema'] !== $b2['ecosistema']) {
        $cu = $a['ecosistema'] === 'CUDA' ? $na : ($b2['ecosistema'] === 'CUDA' ? $nb : null);
        $v1[] = $cu ? 'La ' . $cu . ' usa CUDA, con el que funciona todo el software de IA a la primera, sobre todo imagen y vídeo. La otra va bien con modelos de texto en Ollama o LM Studio, pero pide más configuración para lo demás.'
            : 'Usan software distinto (' . $a['ecosistema'] . ' y ' . $b2['ecosistema'] . '): comprueba que tus programas lo soportan.';
    }
    $gan = $nta === $ntb ? null : ($nta > $ntb ? $a : $b2);
    $fin = $gan ? 'Para IA nos quedamos con la ' . inf_corto($gan) . ' (nota ' . max($nta, $ntb) . ' frente a ' . min($nta, $ntb) . '). Si la diferencia de precio en Amazon es grande, la otra sigue siendo una compra sensata.'
        : 'Para IA es un empate técnico (nota ' . $nta . ' las dos): elige la que esté más barata hoy en Amazon.';
    $h1 = $na . ' vs ' . $nb . ': cuál comprar';
    $bd = '<main class="wrap"><section class="cabecera">' . inf_migas($migas) . '<h1>' . inf_e($h1) . '</h1><p class="muted intro">Comparativa de la ' . inf_e($a['nombre']) . ' y la ' . inf_e($b2['nombre']) . ' pensando en inteligencia artificial en local: memoria, velocidad, consumo y qué modelos mueve cada ' . ($esGpu ? 'tarjeta gráfica' : 'equipo') . '.</p></section>';
    $bd .= '<div class="tabla-wrap surface"><table class="tabla"><thead><tr><th scope="col">Dato</th><th scope="col"><a href="' . esc_url(inf_url(inf_ficha_path($a))) . '">' . inf_e($na) . '</a></th><th scope="col"><a href="' . esc_url(inf_url(inf_ficha_path($b2))) . '">' . inf_e($nb) . '</a></th></tr></thead><tbody>';
    foreach ($filas as $f) {
        $ma1 = $mb1 = false;
        if ($f[5] && $f[1] !== $f[2]) { $ma1 = $f[5] === 'max' ? $f[1] > $f[2] : $f[1] < $f[2]; $mb1 = !$ma1; }
        $bd .= '<tr><th scope="row">' . inf_e($f[0]) . '</th><td' . ($ma1 ? ' class="mejor"' : '') . '>' . inf_e($f[3]) . '</td><td' . ($mb1 ? ' class="mejor"' : '') . '>' . inf_e($f[4]) . '</td></tr>';
    }
    $bd .= '<tr class="comprar"><th scope="row">Precio</th><td>' . inf_btn_amazon($a, 'Ver precio', true) . '</td><td>' . inf_btn_amazon($b2, 'Ver precio', true) . '</td></tr></tbody></table></div>';
    $bd .= '<section class="surface pad"><h2>' . inf_e($na . ' o ' . $nb) . ': nuestro veredicto</h2>';
    foreach ($v1 as $t) $bd .= '<p>' . inf_e($t) . '</p>';
    $bd .= '<p><strong>' . inf_e($fin) . '</strong></p></section>';
    $bd .= '<section class="bloques">';
    foreach ([$a, $b2] as $p) {
        $bd .= '<div class="surface pad"><h2>Lo que no te cuentan de la ' . inf_e(inf_corto($p)) . '</h2><ul class="lista no">';
        foreach ($p['loQueNo'] as $x) $bd .= '<li>' . inf_e($x) . '</li>';
        $bd .= '</ul><p><a href="' . esc_url(inf_url(inf_ficha_path($p))) . '">Ver la ficha completa de la ' . inf_e(inf_corto($p)) . '</a></p></div>';
    }
    $bd .= '</section>';
    $faq = [
        ['¿Cuál es mejor para IA, la ' . $na . ' o la ' . $nb . '?', $fin],
        ['¿Cuál consume menos?', $a['consumo'] === $b2['consumo'] ? 'Consumen lo mismo: ' . $a['consumo'] . ' W.' : 'La ' . ($a['consumo'] < $b2['consumo'] ? $na : $nb) . ', con ' . min($a['consumo'], $b2['consumo']) . ' W frente a ' . max($a['consumo'], $b2['consumo']) . ' W.'],
        ['¿Cuál es más barata?', 'Los precios cambian cada día, por eso no los copiamos: mira el precio actual de las dos en Amazon con los botones de la tabla.'],
    ];
    $bd .= inf_faq($faq) . '<section class="block"><div class="sec-head"><h2>Las dos, frente a frente</h2><p>¿Quieres añadir una tercera? Usa el <a href="' . esc_url(inf_url('/comparador/')) . '">comparador de GPU</a> o la <a href="' . esc_url(inf_url('/calculadora-vram/')) . '">calculadora de VRAM</a>.</p></div><div class="grid-cards">' . inf_card($a) . inf_card($b2) . '</div></section>'
        . '<p class="mini muted">Tokens/s: estimación propia. Enlaces de afiliado de Amazon.</p></main>';
    return ['title' => $na . ' vs ' . $nb . ': comparativa y cuál comprar | ' . INF_NOMBRE,
        'description' => $na . ' vs ' . $nb . ': comparativa de memoria, velocidad, consumo y modelos de IA que mueve cada una. Veredicto claro y precio actual en Amazon.',
        'path' => $path, 'clase' => 'p-comparador p-versus', 'body' => $bd, 'schema' => [inf_schema_migas($migas), inf_schema_faq($faq)]];
}

/** Tarjeta sencilla de un accesorio o componente (sin puntuación de IA). */
function inf_item_card($i) {
    return '<article class="surface pad item reveal">' . inf_foto_prod($i) . '<h3>' . inf_e($i['nombre']) . '</h3><p class="muted">' . inf_e($i['nota']) . '</p>' . inf_btn_amazon($i, 'Ver precio en Amazon', true) . '</article>';
}

/** Venta cruzada en la ficha: lo que hace falta alrededor del equipo. */
function inf_cruzada($p) {
    $ids = [];
    if ($p['tipo'] === 'grafica') {
        $psu = null;
        foreach (inf_grupos() as $g) { if ($g['slug'] !== 'fuentes-de-alimentacion') continue; foreach ($g['items'] as $i) { if (($i['w'] ?? 0) >= $p['fuente'] && (!$psu || $i['w'] < $psu['w'])) $psu = $i; } }
        if ($psu) $ids[] = $psu['id'];
        $ids[] = 'sop1'; $ids[] = 'ssd2';
    } elseif ($p['tipo'] === 'mini-pc') {
        if (strpos($p['slug'], 'minisforum') === 0) { $ids[] = 'egpu1'; $ids[] = 'ram64so'; }
        $ids[] = 'ssd2';
    } else {
        return '';
    }
    $h = '';
    foreach ($ids as $id) { $i = inf_item($id); if ($i) $h .= inf_item_card($i); }
    if (!$h) return '';
    $tit = $p['tipo'] === 'grafica' ? 'Lo que necesitas para montarla' : 'Para sacarle más partido';
    return '<section class="block"><div class="sec-head"><h2>' . $tit . '</h2><p>' . ($p['tipo'] === 'grafica' && $p['fuente'] > 0 ? 'Esta tarjeta gráfica pide una fuente de ' . (int) $p['fuente'] . ' W o más. ' : '') . 'Más opciones en <a href="' . esc_url(inf_url('/componentes/')) . '">componentes para un PC de IA</a>.</p></div><div class="grid-cards">' . $h . '</div></section>';
}

function inf_pag_grupo($slug) {
    $g = null; foreach (inf_grupos() as $x) { if ($x['slug'] === $slug) $g = $x; }
    if (!$g) return null;
    $path = inf_grupo_path($g);
    $migas = !empty($g['raiz']) ? [['/', 'Inicio'], [$path, $g['nombre']]] : [['/', 'Inicio'], ['/componentes/', 'Componentes'], [$path, $g['nombre']]];
    $b = '<main class="wrap"><section class="cabecera">' . inf_migas($migas) . '<h1>' . inf_e($g['titulo']) . '</h1><p class="muted intro">' . inf_e($g['intro']) . '</p></section>'
        . '<section class="surface pad"><h2>Cómo elegir</h2><p>' . inf_e($g['consejo']) . '</p></section>'
        . '<section class="block"><div class="sec-head"><h2>Los más vendidos en Amazon</h2><p>Elegidos entre los más vendidos de Amazon.es. Revisado el ' . inf_fecha('2026-11-11') . '.</p></div><div class="grid-cards">';
    foreach ($g['items'] as $i) $b .= inf_item_card($i);
    $b .= '</div></section><section class="block"><div class="sec-head"><h2>Sigue montando tu equipo de IA</h2></div><ul class="lista enlaces">'
        . '<li><a href="' . esc_url(inf_url('/tarjetas-graficas/')) . '">Tarjetas gráficas (GPU) para IA</a></li>';
    foreach (inf_grupos() as $x) { if ($x['slug'] !== $slug) $b .= '<li><a href="' . esc_url(inf_url(inf_grupo_path($x))) . '">' . inf_e($x['nombre']) . '</a></li>'; }
    $b .= '<li><a href="' . esc_url(inf_url('/calculadora-vram/')) . '">Calculadora de VRAM</a></li></ul></section>'
        . '<p class="aviso muted">Enlaces de afiliado de Amazon: si compras desde ellos recibimos una comisión sin coste para ti. No mostramos precios porque cambian a diario.</p></main>';
    $lista = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $g['titulo'], 'itemListElement' => array_map(fn($i, $k) => ['@type' => 'ListItem', 'position' => $k + 1, 'name' => $i['nombre']], $g['items'], array_keys($g['items']))];
    return ['title' => $g['titulo'] . ' | ' . INF_NOMBRE, 'description' => $g['descripcion'], 'path' => $path, 'clase' => 'p-grupo', 'body' => $b, 'schema' => [inf_schema_migas($migas), $lista]];
}

function inf_pag_componentes() {
    $migas = [['/', 'Inicio'], ['/componentes/', 'Componentes']];
    $b = '<main class="wrap"><section class="cabecera">' . inf_migas($migas) . '<h1>Componentes para montar un PC de IA</h1><p class="muted intro">La tarjeta gráfica es la pieza clave, pero no trabaja sola. Aquí tienes lo que la acompaña: fuente de alimentación, memoria RAM, disco SSD, procesador, placa base, caja y refrigeración, además de las GPU externas para portátil y mini PC. Todo elegido entre los más vendidos de Amazon.</p></section>' . inf_foto_amb_html(inf_foto_amb('componentes', 'hero'), 'sec-portada', false) . '<div class="grid-cards">';
    foreach (inf_grupos() as $g) {
        $b .= '<article class="surface pad item reveal"><h2><a href="' . esc_url(inf_url(inf_grupo_path($g))) . '">' . inf_e($g['nombre']) . '</a></h2><p class="muted">' . inf_e($g['descripcion']) . '</p><a class="btn btn-sm" href="' . esc_url(inf_url(inf_grupo_path($g))) . '">Ver ' . count($g['items']) . ' opciones</a></article>';
    }
    $b .= '</div></main>';
    return ['title' => 'Componentes para un PC de IA: fuente, RAM, SSD y más | ' . INF_NOMBRE, 'description' => 'Todo lo que acompaña a la tarjeta gráfica en un PC de IA: fuentes de alimentación, memoria RAM, SSD, procesadores, placas base, cajas y GPU externas.', 'path' => '/componentes/', 'clase' => 'p-grupo', 'body' => $b, 'schema' => [inf_schema_migas($migas)]];
}

/* ---------- Guías (artículos) ---------- */

function inf_slug_h($s) {
    $s = strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/** Texto de guía: {nota:slug} {tps:slug} {vram:slug} {bw:slug} {w:slug} {mayor:slug} sacan el dato de las fichas; **negrita**; [texto](/ruta/). */
function inf_md($s) {
    $s = preg_replace_callback('/\{(nota|tps|vram|bw|w|mayor):([a-z0-9-]+)\}/', function ($m) {
        $p = inf_producto($m[2]);
        if (!$p) return '?';
        switch ($m[1]) {
            case 'nota': return (string) inf_nota($p);
            case 'vram': return (string) (int) $p['vram'];
            case 'bw': return (string) (int) $p['anchoBanda'];
            case 'w': return (string) (int) $p['consumo'];
            case 'mayor': $x = inf_mayor_modelo($p); return $x ? $x['nombre'] : 'ninguno';
            case 'tps':
                foreach (inf_modelos() as $mo) { if ($mo['params'] == 8) { $t = inf_tokens($p, $mo); return $t ? (string) $t : '0'; } }
        }
        return '?';
    }, $s);
    $s = inf_e($s);
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    return preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function ($m) {
        $u = html_entity_decode($m[2]);
        $ext = (bool) preg_match('#^https?://#', $u);
        return '<a href="' . esc_url($ext ? $u : inf_url($u)) . '"' . ($ext ? ' rel="noopener" target="_blank"' : '') . '>' . $m[1] . '</a>';
    }, $s);
}

function inf_guia_bloque($b) {
    if (isset($b['h3'])) return '<h3>' . inf_md($b['h3']) . '</h3>';
    if (isset($b['p'])) return '<p>' . inf_md($b['p']) . '</p>';
    if (isset($b['ul']) || isset($b['ol'])) {
        $t = isset($b['ol']) ? 'ol' : 'ul'; $h = '<' . $t . ' class="lista' . ($t === 'ol' ? ' num' : '') . '">';
        foreach ($b[$t] as $x) $h .= '<li>' . inf_md($x) . '</li>';
        return $h . '</' . $t . '>';
    }
    if (isset($b['aviso'])) return '<aside class="aviso-g reveal"><b>' . inf_e($b['titulo'] ?? 'Ojo con esto') . '</b><p>' . inf_md($b['aviso']) . '</p></aside>';
    if (isset($b['stats'])) {
        $h = '<div class="stats-g reveal">';
        foreach ($b['stats'] as $x) $h .= '<div class="surface"><b class="copper-text">' . inf_e($x[0]) . '</b><span>' . inf_md($x[1]) . '</span></div>';
        return $h . '</div>';
    }
    if (isset($b['tabla'])) {
        $t = $b['tabla']; $h = '<div class="tabla-wrap surface reveal"><table class="tabla tabla-g"><thead><tr>';
        foreach ($t['cab'] as $c) $h .= '<th scope="col">' . inf_md($c) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($t['filas'] as $f) { $h .= '<tr>'; foreach ($f as $k => $c) $h .= $k === 0 ? '<th scope="row">' . inf_md($c) . '</th>' : '<td>' . inf_md($c) . '</td>'; $h .= '</tr>'; }
        return $h . '</tbody></table>' . (!empty($t['nota']) ? '<p class="mini muted">' . inf_md($t['nota']) . '</p>' : '') . '</div>';
    }
    if (isset($b['barras'])) {
        $d = $b['barras']; $max = 0; $num = fn($v) => is_numeric($v) ? (float) $v : (float) strip_tags(inf_md($v));
        foreach ($d['datos'] as $x) $max = max($max, $num($x[1]));
        $h = '<figure class="barras surface reveal"><figcaption>' . inf_md($d['titulo']) . '</figcaption><ul>';
        foreach ($d['datos'] as $x) $h .= '<li><span class="b-n">' . inf_md($x[0]) . '</span><span class="meter"><i style="--w:' . round($num($x[1]) / $max * 100, 1) . '%"></i></span><span class="b-v mono">' . (isset($x[2]) ? inf_md($x[2]) : inf_e($x[1] . ' ' . ($d['unidad'] ?? ''))) . '</span></li>';
        return $h . '</ul>' . (!empty($d['nota']) ? '<p class="mini muted">' . inf_md($d['nota']) . '</p>' : '') . '</figure>';
    }
    if (isset($b['pick'])) {
        $p = inf_producto($b['pick']);
        if (!$p) return '';
        $u = esc_url(inf_url(inf_ficha_path($p)));
        return '<article class="surface pick reveal"><div class="pick-art">' . inf_chip_art($p) . '</div><div class="pick-body">'
            . (!empty($b['rol']) ? '<span class="badge badge-copper">' . inf_e($b['rol']) . '</span>' : '')
            . '<h3><a href="' . $u . '">' . inf_e($p['nombre']) . '</a></h3><p>' . inf_md($b['texto'] ?? $p['resumen']) . '</p>'
            . '<dl class="pick-specs"><div><dt>Memoria</dt><dd>' . (int) $p['vram'] . ' GB</dd></div><div><dt>Ancho de banda</dt><dd>' . (int) $p['anchoBanda'] . ' GB/s</dd></div><div><dt>Consumo</dt><dd>' . (int) $p['consumo'] . ' W</dd></div><div><dt>Nota IA</dt><dd>' . inf_nota($p) . '</dd></div></dl>'
            . '<div class="acciones">' . inf_btn_amazon($p, 'Ver en Amazon', true) . '<a class="btn btn-sm" href="' . $u . '">Ver ficha</a></div></div></article>';
    }
    if (isset($b['cta'])) {
        $c = $b['cta'];
        return '<aside class="surface cta-g reveal"><p>' . inf_md($c['texto']) . '</p><a class="btn btn-copper" href="' . esc_url(inf_url($c['url'])) . '">' . inf_e($c['boton']) . '</a></aside>';
    }
    return '';
}

function inf_guia_texto($g) {
    $t = $g['entradilla'] . ' ' . implode(' ', $g['resumen']);
    foreach ($g['secciones'] as $s) { $t .= ' ' . $s['h2']; foreach ($s['bloques'] as $b) $t .= ' ' . strip_tags(inf_guia_bloque($b)); }
    foreach ($g['faq'] as $f) $t .= ' ' . $f[0] . ' ' . $f[1];
    return html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
}

function inf_pag_guia($slug) {
    $g = inf_guia($slug);
    if (!$g) return null;
    $path = '/guias/' . $slug . '/';
    $migas = [['/', 'Inicio'], ['/guias/', 'Guías'], [$path, $g['titulo']]];
    $palabras = count(preg_split('/\s+/u', trim(inf_guia_texto($g))));
    $min = max(1, (int) ceil($palabras / 200));
    $toc = ''; $secs = '';
    // Fotos de texto: una cada pocas secciones (f1, f2, f3...), repartidas por la guía.
    $ns = count($g['secciones']); $fotos = [];
    for ($k = 1; $k <= 6; $k++) { $f = inf_foto_amb($g['slug'], 'f' . $k); if ($f) $fotos[] = $f; }
    $cada = $fotos ? max(1, (int) floor($ns / (count($fotos) + 1))) : 0;
    foreach ($g['secciones'] as $i => $s) {
        $id = inf_slug_h($s['h2']);
        $toc .= '<li><a href="#' . $id . '">' . inf_md($s['h2']) . '</a></li>';
        $secs .= '<section class="g-sec" id="' . $id . '"><h2>' . inf_md($s['h2']) . '</h2>';
        foreach ($s['bloques'] as $b) $secs .= inf_guia_bloque($b);
        $secs .= '</section>';
        if ($cada && ($i + 1) % $cada === 0 && $fotos && $i < $ns - 1) $secs .= inf_foto_amb_html(array_shift($fotos), 'g-foto');
    }
    $hero = inf_foto_amb($g['slug'], 'hero');
    $comp = $g['tipo'] === 'comparativa';
    $b = '<main class="wrap guia"><header class="cabecera g-hero">' . inf_migas($migas)
        . '<span class="badge badge-copper">' . ($comp ? 'Comparativa' : 'Guía') . '</span><h1>' . inf_e($g['titulo']) . '</h1><p class="muted intro">' . inf_md($g['entradilla']) . '</p>'
        . '<p class="g-meta mono">Por ' . INF_AUTOR . ' · ' . inf_fecha($g['fecha']) . ' · ' . $min . ' min de lectura</p></header>'
        . inf_foto_amb_html($hero, 'g-portada', false)
        . '<div class="g-cuerpo"><aside class="surface g-resumen"><h2>En resumen</h2><ul class="lista">';
    foreach ($g['resumen'] as $x) $b .= '<li>' . inf_md($x) . '</li>';
    $b .= '</ul></aside><nav class="toc surface" aria-label="Índice" data-nocount><details><summary>Índice de la guía</summary><ol>' . $toc . '</ol></details></nav>'
        . $secs . inf_faq($g['faq'], 'Preguntas frecuentes');
    $rel = [];
    foreach (($g['relacionadas'] ?? []) as $rs) { $x = inf_guia($rs); if ($x) $rel[] = $x; }
    if ($rel) {
        $b .= '<section class="g-sec"><h2>Sigue leyendo</h2><ul class="lista enlaces">';
        foreach ($rel as $x) $b .= '<li><a href="' . esc_url(inf_url('/guias/' . $x['slug'] . '/')) . '">' . inf_e($x['titulo']) . '</a></li>';
        $b .= '</ul></section>';
    }
    $b .= '<p class="mini muted">Enlaces de afiliado de Amazon: si compras desde ellos recibimos una comisión sin coste para ti. No mostramos precios porque cambian a diario. Las cifras de tokens por segundo son estimaciones propias a partir del ancho de banda, no pruebas medidas.</p></div></main>';
    $art = ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $g['titulo'], 'description' => $g['descripcion'], 'author' => ['@type' => 'Person', 'name' => INF_AUTOR],
        'publisher' => ['@type' => 'Organization', 'name' => INF_NOMBRE, 'url' => home_url('/')], 'datePublished' => $g['fecha'], 'dateModified' => $g['actualizado'] ?? $g['fecha'], 'mainEntityOfPage' => home_url($path), 'inLanguage' => 'es'];
    if ($hero) $art['image'] = content_url('/inferium/img/a/' . $hero['archivo']);
    return ['title' => $g['seoTitulo'] . ' | ' . INF_NOMBRE, 'description' => $g['descripcion'], 'path' => $path, 'clase' => 'p-guia', 'og' => 'article', 'imagen' => $art['image'] ?? '', 'body' => $b, 'schema' => [inf_schema_migas($migas), $art, inf_schema_faq($g['faq'])]];
}

function inf_pag_guias() {
    $migas = [['/', 'Inicio'], ['/guias/', 'Guías']];
    $b = '<main class="wrap"><section class="cabecera">' . inf_migas($migas) . '<h1>Guías de hardware para IA local</h1><p class="muted intro">Explicaciones claras y comparativas honestas para elegir tarjeta gráfica, mini PC o Mac si quieres ejecutar inteligencia artificial en tu propio ordenador. Sin jerga innecesaria: cuando hay que ser técnico lo somos, y cuando basta con decirlo fácil, también.</p></section><div class="grid-cards">';
    $gs = inf_guias();
    usort($gs, fn($x, $y) => [$x['tipo'] === 'comparativa' ? 0 : 1, $y['fecha']] <=> [$y['tipo'] === 'comparativa' ? 0 : 1, $x['fecha']]);
    foreach ($gs as $g) {
        $u = esc_url(inf_url('/guias/' . $g['slug'] . '/'));
        $b .= '<article class="surface pad item reveal"><span class="badge badge-copper">' . ($g['tipo'] === 'comparativa' ? 'Comparativa' : 'Guía') . '</span><h2><a href="' . $u . '">' . inf_e($g['titulo']) . '</a></h2><p class="muted">' . inf_md($g['descripcion']) . '</p><a class="btn btn-sm" href="' . $u . '">Leer</a></article>';
    }
    $b .= '</div></main>';
    return ['title' => 'Guías de hardware para IA local: GPU, mini PC y Mac | ' . INF_NOMBRE, 'description' => 'Guías y comparativas para elegir tarjeta gráfica, mini PC o Mac para ejecutar inteligencia artificial en local: VRAM, Ollama, eGPU y más.', 'path' => '/guias/', 'clase' => 'p-guias', 'body' => $b, 'schema' => [inf_schema_migas($migas)]];
}

function inf_pag_calculadora() {
    $migas = [['/', 'Inicio'], ['/calculadora-vram/', 'Calculadora de VRAM']];
    $faq = [
        ['¿Cuánta VRAM necesita Llama 3 70B?', 'En Q4 unos 47 GB contando el contexto: no cabe en una sola gráfica de consumo. Necesitas dos de 24 GB, una de 48 GB o un equipo de memoria unificada de 64 GB o más.'],
        ['¿Qué es la cuantización Q4?', 'Guardar los pesos del modelo con unos 4 bits en lugar de 16. Ocupa casi cuatro veces menos y la calidad apenas baja en modelos grandes.'],
        ['¿Qué pasa si el modelo no cabe en la VRAM?', 'Herramientas como Ollama o LM Studio pasan parte a la RAM del PC, pero la velocidad cae muchísimo: de decenas de tokens por segundo a pocos.'],
        ['¿Cuenta la memoria unificada de Mac o Ryzen AI Max como VRAM?', 'Sí, casi toda. En Mac el sistema reserva una parte; en Ryzen AI Max se pueden asignar hasta 96 GB de los 128 a la gráfica.'],
    ];
    $b = '<main class="wrap"><section class="cabecera">' . inf_migas($migas) . '<h1>¿Qué modelo de IA cabe en mi GPU?</h1><p class="muted intro">Elige la memoria de tu gráfica y la cuantización. Verás qué modelos de texto e imagen puedes correr en local y el equipo más ajustado si te falta memoria.</p></section>'
        . inf_calc() . '<section class="block prose"><h2>Cómo se calcula</h2>'
        . '<p>Un modelo ocupa, en GB, sus miles de millones de parámetros multiplicados por los bits de cada peso y divididos entre 8. A eso le sumamos un 20 % para el contexto de la conversación (la caché KV) y el resto de la memoria que usa el programa.</p>'
        . '<p>Ejemplo: Qwen 2.5 32B en Q4 (unos 4,5 bits reales) ocupa 32 × 4,5 ÷ 8 = 18 GB de pesos, más un 20 %: unos 21,6 GB. Cabe en una gráfica de 24 GB, no en una de 16.</p>'
        . '<p>Para los modelos de imagen usamos el consumo típico: SDXL va bien en 8 GB y Flux.1 dev en FP8 pide 16 GB.</p></section>' . inf_faq($faq) . '</main>';
    return ['title' => 'Calculadora de VRAM: ¿qué modelo de IA cabe en mi GPU? | ' . INF_NOMBRE, 'description' => 'Calcula qué modelos de IA (Llama, Qwen, DeepSeek, Flux, SDXL) caben en la VRAM de tu GPU según la cuantización, y qué tarjeta gráfica necesitas.',
        'path' => '/calculadora-vram/', 'clase' => 'p-calculadora', 'body' => $b,
        'schema' => [inf_schema_migas($migas), inf_schema_faq($faq), ['@context' => 'https://schema.org', '@type' => 'WebApplication', 'name' => 'Calculadora de VRAM', 'applicationCategory' => 'UtilityApplication', 'operatingSystem' => 'Web', 'offers' => ['@type' => 'Offer', 'price' => 0, 'priceCurrency' => 'EUR']]]];
}

function inf_pag_texto($layout) {
    if ($layout === 'como-elegimos') {
        $path = '/como-elegimos/'; $tit = 'Cómo elegimos';
        $title = 'Cómo elegimos y puntuamos el hardware para IA | ' . INF_NOMBRE;
        $desc = 'Criterios y fórmulas públicas con las que puntuamos tarjetas gráficas y equipos para IA: VRAM, ancho de banda, eficiencia, ecosistema y consumo.';
        $txt = '<p>No cobramos por aparecer ni puntuamos según lo que nos regalen. Partimos de las especificaciones oficiales, las revisamos a mano y calculamos con fórmulas públicas lo que importa para la IA. Cada ficha lleva la fecha de su última revisión.</p>'
            . '<h2>La puntuación (0 a 100)</h2><p>Cada ficha tiene un radar con cinco ejes. La <b>nota IA</b> es la media de los tres que más pesan para IA (VRAM, ancho de banda y ecosistema); eficiencia y consumo se ven en el radar para que decidas tú.</p><ul>'
            . '<li><b>VRAM</b>: escala logarítmica de 4 GB (0) a 512 GB (100), porque pasar de 8 a 16 GB cambia más que pasar de 256 a 264.</li>'
            . '<li><b>Ancho de banda</b>: GB/s divididos entre 1.792 (la RTX 5090 es 100).</li>'
            . '<li><b>Eficiencia</b>: GB/s por vatio, relativo al mejor equipo del catálogo.</li>'
            . '<li><b>Ecosistema</b>: CUDA 100, Metal 70, ROCm 60, oneAPI 45. Mide cuánto software de IA funciona sin pelearse.</li>'
            . '<li><b>Bajo consumo</b>: 100 menos los vatios divididos entre 6 (600 W o más es 0).</li></ul>'
            . '<h2>Modelos que caben</h2><p>Memoria necesaria = parámetros × bits ÷ 8 + 20 % de contexto. En Q4 usamos 4,5 bits por peso, que es lo que ocupan de verdad los formatos habituales (GGUF Q4_K_M).</p>'
            . '<h2>Tokens por segundo</h2><p>Al generar texto, cada token obliga a leer todos los pesos de la memoria. Por eso estimamos: tokens/s ≈ ancho de banda × 0,7 ÷ tamaño de los pesos. El 0,7 recoge que ningún equipo aprovecha el 100 % de su ancho de banda. <b>Es una estimación, no un benchmark</b>: cuando tengamos medidas reales lo indicaremos en cada ficha.</p>'
            . '<h2>Lo que no</h2><p>Toda ficha tiene un apartado "Lo que no" con sus limitaciones reales: fuente necesaria, software que no va, versiones con menos memoria y mismo nombre. Si un producto no tiene nada malo que contar, no lo hemos mirado bien.</p>'
            . '<h2>Precios</h2><p>No los copiamos: cambian varias veces al día. Todos los botones llevan al precio actual en Amazon.</p>';
    } elseif ($layout === 'legal') {
        $path = '/aviso-legal/'; $tit = 'Aviso legal';
        $title = 'Aviso legal | ' . INF_NOMBRE;
        $desc = 'Titular, condiciones de uso y responsabilidad de ' . INF_NOMBRE . '.';
        $txt = '<h2>Titular del sitio web</h2><p>El titular de ' . INF_NOMBRE . ' (inferium.es) es ' . INF_AUTOR . '. Puedes escribirle a <a href="mailto:contacto@inferium.es">contacto@inferium.es</a>.</p>'
            . '<h2>Qué es este sitio</h2><p>' . INF_NOMBRE . ' es una web informativa sobre hardware para inteligencia artificial en local: tarjetas gráficas, mini PC, Mac y componentes. Ofrece fichas, comparativas y herramientas de cálculo. No vende productos: te lleva a Amazon.es para que los compres allí.</p>'
            . '<h2>Enlaces de afiliado</h2><p>' . INF_NOMBRE . ' participa en el Programa de Afiliados de Amazon EU. Los enlaces a Amazon llevan nuestro identificador de afiliado y, si compras desde ellos, recibimos una comisión sin coste adicional para ti. Más detalles en el <a href="' . esc_url(inf_url('/aviso-afiliacion/')) . '">aviso de afiliación</a>.</p>'
            . '<h2>Uso del sitio</h2><p>Al navegar por esta web aceptas usarla de forma lícita y sin dañar su funcionamiento. El contenido es informativo: las especificaciones proceden de fuentes oficiales y las cifras de velocidad (tokens por segundo) son estimaciones propias, no pruebas medidas. Antes de comprar, comprueba siempre las características y el precio en la ficha del producto en Amazon.</p>'
            . '<h2>Responsabilidad</h2><p>Hacemos lo posible por mantener los datos al día, pero no garantizamos que no haya errores ni que un producto siga disponible o mantenga su precio en Amazon. No nos hacemos responsables de las decisiones de compra ni de los contenidos de webs de terceros a las que enlazamos.</p>'
            . '<h2>Propiedad intelectual</h2><p>Los textos, el diseño, las ilustraciones y el código de ' . INF_NOMBRE . ' son de su titular. Las marcas y nombres de productos pertenecen a sus propietarios y se citan solo con fines informativos. Puedes citar fragmentos enlazando a la página original.</p>'
            . '<h2>Ley aplicable</h2><p>Este aviso se rige por la legislación española. Para cualquier conflicto serán competentes los juzgados y tribunales que correspondan según la normativa vigente.</p>'
            . '<p class="muted">Última actualización: 12/11/2026.</p>';
    } elseif ($layout === 'privacidad') {
        $path = '/politica-de-privacidad/'; $tit = 'Política de privacidad';
        $title = 'Política de privacidad | ' . INF_NOMBRE;
        $desc = 'Qué datos trata ' . INF_NOMBRE . ', con qué finalidad y cómo ejercer tus derechos.';
        $txt = '<h2>Responsable</h2><p>' . INF_AUTOR . ', titular de ' . INF_NOMBRE . ' (inferium.es). Contacto: <a href="mailto:contacto@inferium.es">contacto@inferium.es</a>.</p>'
            . '<h2>Qué datos tratamos</h2><p>La web no tiene registro ni formularios. Solo tratamos datos en dos casos:</p><ul>'
            . '<li><b>Medición de visitas</b>, si aceptas las cookies. Usamos <b>Google Analytics 4</b> (Google) para saber qué páginas se visitan y de dónde llegan, y <b>Microsoft Clarity</b> (Microsoft) para ver dónde se hace clic y hasta dónde se lee, con mapas de calor y grabaciones de la sesión. Clarity oculta por defecto el contenido sensible. En Analytics anonimizamos la dirección IP.</li>'
            . '<li><b>Correo electrónico</b>, si nos escribes a contacto@inferium.es. Lo usamos solo para responderte.</li></ul>'
            . '<h2>Base legal</h2><p>Para la medición, tu consentimiento, que das al aceptar el aviso de cookies y puedes retirar cuando quieras. Para responder a tu correo, tu propia petición.</p>'
            . '<h2>Quién más recibe datos</h2><p>Google y Microsoft actúan como proveedores de las herramientas de medición. Pueden tratar datos fuera del Espacio Económico Europeo con las garantías que indican en sus propias políticas de privacidad. Si compras en Amazon desde uno de nuestros enlaces, Amazon aplica su política de privacidad y de cookies.</p>'
            . '<h2>Cuánto tiempo los conservamos</h2><p>Los datos de Analytics se conservan como máximo 14 meses. Los de Clarity, según los plazos de la herramienta. Tu correo, el tiempo necesario para atender tu consulta.</p>'
            . '<h2>Tus derechos</h2><p>Puedes pedir acceso, rectificación, supresión, oposición, limitación o portabilidad de tus datos escribiendo a <a href="mailto:contacto@inferium.es">contacto@inferium.es</a>. Si crees que no hemos tratado bien tus datos, puedes reclamar ante la Agencia Española de Protección de Datos (aepd.es).</p>'
            . '<h2>Cookies</h2><p>En la página de <a href="' . esc_url(inf_url('/cookies/')) . '">privacidad y cookies</a> puedes ver cuáles usamos y cambiar tu elección.</p>'
            . '<p class="muted">Última actualización: 12/11/2026.</p>';
    } elseif ($layout === 'cookies') {
        $path = '/cookies/'; $tit = 'Privacidad y cookies';
        $title = 'Privacidad y cookies | ' . INF_NOMBRE;
        $desc = 'Qué cookies usa ' . INF_NOMBRE . ', para qué sirven y cómo aceptarlas o rechazarlas.';
        $txt = '<p>' . INF_NOMBRE . ' no te pide registro ni guarda datos personales tuyos. Solo usamos dos cosas que conviene que conozcas.</p>'
            . '<h2>Cookies de medición</h2><p>Si las aceptas en el aviso, usamos Google Analytics 4 y Microsoft Clarity para saber qué páginas se visitan, en qué se hace clic y hasta dónde se lee, y así mejorar la web. Si las rechazas, no se carga ninguna de las dos. Tu elección se guarda en tu navegador.</p>'
            . '<p><button class="btn btn-sm" type="button" data-cookies-reset>Cambiar mi elección de cookies</button></p>'
            . '<h2>Enlaces a Amazon</h2><p>Los botones de compra llevan a Amazon.es con nuestro identificador de afiliado. Al llegar allí, Amazon aplica su propia política de privacidad y de cookies.</p>'
            . '<h2>Responsable</h2><p>El responsable de esta web es ' . INF_AUTOR . '. Más datos en la <a href="' . esc_url(inf_url('/politica-de-privacidad/')) . '">política de privacidad</a> y en el <a href="' . esc_url(inf_url('/aviso-legal/')) . '">aviso legal</a>.</p>';
    } else {
        $path = '/aviso-afiliacion/'; $tit = 'Aviso de afiliación';
        $title = 'Aviso de afiliación | ' . INF_NOMBRE;
        $desc = 'Cómo se financia ' . INF_NOMBRE . ': enlaces de afiliado de Amazon sin coste para ti.';
        $txt = '<p>' . INF_NOMBRE . ' participa en el Programa de Afiliados de Amazon EU, un programa de publicidad para afiliados diseñado para ofrecer a sitios web un modo de obtener comisiones por publicidad, publicitando e incluyendo enlaces a Amazon.es.</p>'
            . '<p>En calidad de Afiliado de Amazon, obtenemos ingresos por las compras adscritas que cumplen los requisitos aplicables. Tú pagas exactamente lo mismo.</p>'
            . '<p>Los enlaces a Amazon están marcados como patrocinados. Ninguna marca paga por aparecer ni por su puntuación: puedes ver cómo puntuamos en <a href="' . esc_url(inf_url('/como-elegimos/')) . '">Cómo elegimos</a>.</p>';
    }
    $migas = [['/', 'Inicio'], [$path, $tit]];
    return ['title' => $title, 'description' => $desc, 'path' => $path, 'clase' => 'p-texto', 'schema' => [inf_schema_migas($migas)],
        'body' => '<main class="wrap texto">' . inf_migas($migas) . '<article class="prose"><h1>' . $tit . '</h1>' . $txt . '</article></main>'];
}

/** Landing pública mientras la web se prepara. */
function inf_pag_proximamente() {
    $b = '<main class="prox"><canvas id="die" aria-hidden="true"></canvas>'
        . '<div class="wrap prox-top"><span class="logo"><span class="logo-mark" aria-hidden="true"></span>' . INF_NOMBRE . '</span></div>'
        . '<div class="wrap prox-main"><div class="prox-txt"><h1><span class="neon">Próximamente</span><small>La guía de hardware para correr IA en tu propia máquina.</small></h1>'
        . '<p class="lede">Tarjetas gráficas, mini PCs y Mac comparados por lo que importa para la IA: <strong>cuánta memoria tienen y qué modelos te caben</strong>. Estamos afinando los últimos detalles.</p></div>'
        . '<div class="escena" aria-hidden="true"><div class="gpu3d"><i class="pcb2"></i><i class="pcb"></i><i class="mem"></i><i class="base2"></i><i class="base"></i><i class="die2"></i><i class="die"></i><i class="luz"></i><i class="sello">IA</i></div></div></div>'
        . '<div class="wrap prox-pie">© ' . date('Y') . ' ' . INF_NOMBRE . '</div></main>';
    return ['title' => INF_NOMBRE . ' | Hardware para IA local. Próximamente', 'description' => 'Inferium abre pronto: tarjetas gráficas, mini PCs y Mac para IA local, comparados por memoria y por los modelos que caben.',
        'path' => '/', 'clase' => 'p-prox', 'simple' => true, 'body' => $b];
}

function inf_pagina($layout, $slug = '') {
    switch ($layout) {
        case 'proximamente': return inf_pag_proximamente();
        case 'inicio': return inf_pag_inicio();
        case 'categoria': return inf_pag_categoria($slug);
        case 'catalogo': return inf_pag_catalogo();
        case 'ficha': return inf_pag_ficha($slug);
        case 'comparador': return inf_pag_comparador();
        case 'versus': return inf_pag_versus($slug);
        case 'grupo': return inf_pag_grupo($slug);
        case 'guia': return inf_pag_guia($slug);
        case 'guias': return inf_pag_guias();
        case 'componentes': return inf_pag_componentes();
        case 'calculadora': return inf_pag_calculadora();
        case 'como-elegimos': case 'aviso': case 'cookies': case 'legal': case 'privacidad': return inf_pag_texto($layout);
    }
    return null;
}

/* ---------- Enganche con WordPress ---------- */

add_action('template_redirect', function () {
    if (!is_page()) return;
    $post = get_queried_object();
    if (!$post || empty($post->ID)) return;
    $layout = get_post_meta($post->ID, '_inferium_layout', true);
    if (!$layout) return;
    $pag = inf_pagina($layout, (string) get_post_meta($post->ID, '_inferium_slug', true));
    if (!$pag) return;
    $pag['borrador'] = $post->post_status !== 'publish';
    if (in_array($layout, ['legal', 'privacidad', 'cookies'], true)) $pag['noindex'] = true;
    if ($layout === 'inicio' && is_front_page()) $pag['path'] = '/';
    if ($pag['borrador']) nocache_headers();
    status_header(200);
    header('Content-Type: text/html; charset=utf-8');
    echo inf_documento($pag);
    exit;
}, 1);

/** El <title> del listado de wp-admin y los buscadores usan el título de la página; nada más que enganchar. */
