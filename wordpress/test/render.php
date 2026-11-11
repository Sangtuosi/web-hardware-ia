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
$pags = [['proximamente', '', '/'], ['inicio', '', '/inicio/'], ['catalogo', '', '/hardware/'], ['comparador', '', '/comparador/'], ['calculadora', '', '/calculadora-vram/'], ['como-elegimos', '', '/como-elegimos/'], ['aviso', '', '/aviso-afiliacion/']];
foreach (inf_categorias() as $c) $pags[] = ['categoria', $c['slug'], '/' . $c['slug'] . '/'];
foreach (inf_productos() as $p) $pags[] = ['ficha', $p['slug'], '/hardware/' . $p['slug'] . '/'];
foreach ($pags as [$l, $s, $path]) {
    $pag = inf_pagina($l, $s);
    ok($pag !== null, "pagina $l $s");
    $html = inf_documento($pag + ['borrador' => $l !== 'proximamente']);
    ok(!str_contains($html, '—'), "raya larga en $path");
    @mkdir($out . $path, 0777, true);
    file_put_contents($out . $path . 'index.html', $html);
}
echo count($pags) . " paginas, $fallos fallos\n";
exit($fallos ? 1 : 0);
