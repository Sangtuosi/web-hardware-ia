<?php
// Prueba local sin WordPress: define lo mínimo, pinta todas las páginas en un directorio y comprueba cálculos.
// Uso: php wordpress/test/render.php <dir_salida>
define('ABSPATH', '/'); define('WP_CONTENT_DIR', __DIR__ . '/_content');
@mkdir(WP_CONTENT_DIR, 0777, true); @symlink(dirname(__DIR__), WP_CONTENT_DIR . '/inferium');
$GLOBALS['opts'] = ['blog_public' => 0];
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function home_url($p = '/') { return $p; }
function content_url($p = '') { return '/wp-content/' . $p; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return str_replace('&', '&#038;', $s); }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function get_post_meta() { return ''; }
function add_action() {}
require dirname(__DIR__) . '/inferium.php';

$fallos = 0;
function ok($c, $m) { global $fallos; if (!$c) { $fallos++; echo "FALLO: $m\n"; } }
ok(abs(inf_vram_necesaria(['params' => 8, 'tipo' => 'texto']) - 5.4) < 0.001, 'vram 8B');
ok(inf_tokens(inf_producto('nvidia-rtx-5090'), ['params' => 8, 'tipo' => 'texto']) === 279, 'tokens 5090');
ok(inf_tokens(inf_producto('nvidia-rtx-5090'), ['params' => 70, 'tipo' => 'texto']) === null, '70B no cabe en 5090');
ok(inf_nota(inf_producto('nvidia-rtx-5090')) === 81, 'nota 5090');
ok(str_contains(inf_amazon_url(['asin' => 'B000TEST01', 'nombre' => 'x']), '/dp/B000TEST01?tag=PENDIENTE-21'), 'tag en enlace');

$out = $argv[1] ?? sys_get_temp_dir() . '/inferium-out';
$pags = [['proximamente', '', '/'], ['inicio', '', '/inicio/'], ['catalogo', '', '/hardware/'], ['comparador', '', '/comparador/'], ['calculadora', '', '/calculadora-vram/'], ['como-elegimos', '', '/como-elegimos/'], ['aviso', '', '/aviso-afiliacion/'], ['cookies', '', '/cookies/'], ['legal', '', '/aviso-legal/'], ['privacidad', '', '/politica-de-privacidad/']];
foreach (inf_categorias() as $c) $pags[] = ['categoria', $c['slug'], '/' . $c['slug'] . '/'];
foreach (inf_productos() as $p) $pags[] = ['ficha', $p['slug'], inf_ficha_path($p)];
$pags[] = ['componentes', '', '/componentes/'];
foreach (inf_grupos() as $g) $pags[] = ['grupo', $g['slug'], inf_grupo_path($g)];
foreach (inf_versus() as $v) $pags[] = ['versus', $v['slug'], '/comparador/' . $v['slug'] . '/'];
foreach ($pags as [$l, $s, $path]) {
    $pag = inf_pagina($l, $s);
    ok($pag !== null, "pagina $l $s");
    $html = inf_documento($pag + ['borrador' => $l !== 'proximamente']);
    ok(!str_contains($html, '—'), "raya larga en $path");
    @mkdir($out . $path, 0777, true);
    file_put_contents($out . $path . 'index.html', $html);
}
ok(inf_ficha_path(inf_producto('nvidia-rtx-5090')) === '/tarjetas-graficas/nvidia-rtx-5090/', 'ruta por tipo');
ok(count(inf_de_categoria(inf_categoria('tarjetas-graficas'))) === 16, '16 GPU');
foreach (inf_categorias() as $c) ok(count(inf_de_categoria($c)) > 0, 'categoria vacia ' . $c['slug']);
$GLOBALS['opts'] += ['inferium_gsc' => 'abc_123-XYZ', 'inferium_ga4' => 'G-TEST12345'];
$pub = inf_documento(inf_pagina('inicio')); $bor = inf_documento(inf_pagina('inicio') + ['borrador' => true]);
ok(str_contains($pub, 'google-site-verification" content="abc_123-XYZ"') && str_contains($bor, 'google-site-verification'), 'meta search console');
ok(str_contains($pub, 'G-TEST12345') && str_contains($pub, 'data-cookies-si'), 'analytics con aviso en publicadas');
ok(!str_contains($bor, 'G-TEST12345'), 'sin analytics en borradores');
ok(str_contains($pub, 'data-prod="'), 'producto en boton amazon');
$GLOBALS['opts']['inferium_clarity'] = 'abc123xyz'; ok(str_contains(inf_documento(inf_pagina('inicio')), 'clarity.ms/tag/') && str_contains(inf_documento(inf_pagina('inicio')), 'cl="abc123xyz"'), 'clarity');
unset($GLOBALS['opts']['inferium_clarity']);
$GLOBALS['opts']['inferium_ga4'] = '"><script>'; ok(!str_contains(inf_documento(inf_pagina('inicio')), 'googletagmanager'), 'ga4 invalido se ignora');
$ids = []; foreach (inf_grupos() as $g) foreach ($g['items'] as $i) { ok(!in_array($i['id'], $ids, true), 'id repetido ' . $i['id']); $ids[] = $i['id']; }
ok(str_contains(inf_cruzada(inf_producto('nvidia-rtx-5080')), 'RM850x') && str_contains(inf_cruzada(inf_producto('nvidia-rtx-5090')), '1200 W') && str_contains(inf_cruzada(inf_producto('nvidia-rtx-5060')), 'RM650e'), 'fuente segun gpu');
echo count($pags) . " paginas, $fallos fallos\n";
exit($fallos ? 1 : 0);
