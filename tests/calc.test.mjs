import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { vramNecesaria, cabe, tokensPorSegundo, puntuaciones, recomendado } from '../src/lib/calc.js';
import { amazonUrl, AMAZON_TAG } from '../src/lib/site.js';

const productos = JSON.parse(readFileSync(new URL('../src/data/productos.json', import.meta.url)));
const categorias = JSON.parse(readFileSync(new URL('../src/data/categorias.json', import.meta.url)));
const p = (slug) => productos.find((x) => x.slug === slug);
const llama8 = { nombre: 'Llama 3.1 8B', tipo: 'texto', params: 8 };
const llama70 = { nombre: 'Llama 3.3 70B', tipo: 'texto', params: 70 };

test('VRAM necesaria: params × bits ÷ 8 + 20 %', () => {
  assert.equal(vramNecesaria(llama8, 4.5).toFixed(1), '5.4');
  assert.equal(vramNecesaria(llama70, 16), 168);
});

test('Llama 70B Q4 no cabe en 32 GB pero sí en 128 GB', () => {
  assert.equal(cabe(llama70, 32), false);
  assert.equal(cabe(llama70, 128), true);
});

test('tokens/s sale del ancho de banda y es null si no cabe', () => {
  assert.equal(tokensPorSegundo(p('nvidia-rtx-5090'), llama8), 279);
  assert.equal(tokensPorSegundo(p('nvidia-rtx-5090'), llama70), null);
});

test('puntuaciones entre 0 y 100', () => {
  for (const prod of productos) {
    for (const v of Object.values(puntuaciones(prod, productos))) assert.ok(v >= 0 && v <= 100, prod.slug);
  }
  assert.equal(puntuaciones(p('nvidia-rtx-5090'), productos)['Ancho de banda'], 100);
});

test('recomendado elige el equipo más justo', () => {
  assert.equal(recomendado(24, productos).slug, 'nvidia-rtx-4090');
  assert.equal(recomendado(1000, productos), null);
});

test('enlace de Amazon siempre lleva el tag', () => {
  assert.match(amazonUrl({ asin: 'B000TEST01', nombre: 'x' }), new RegExp(`/dp/B000TEST01\\?tag=${AMAZON_TAG}$`));
  assert.match(amazonUrl({ asin: '', nombre: 'RTX 5090' }), new RegExp(`tag=${AMAZON_TAG}`));
});

test('datos coherentes: slugs únicos, categorías existentes, "lo que no" relleno', () => {
  const slugs = new Set(productos.map((x) => x.slug));
  assert.equal(slugs.size, productos.length);
  const cats = new Set(categorias.map((c) => c.slug));
  for (const prod of productos) {
    assert.ok(prod.categorias.every((c) => cats.has(c)), prod.slug);
    assert.ok(prod.loQueNo.length > 0, prod.slug);
  }
  for (const c of categorias) assert.ok(productos.some((x) => x.categorias.includes(c.slug)), c.slug);
});

test('nota IA: la 5090 supera a la Jetson', async () => {
  const { notaIA } = await import('../src/lib/calc.js');
  assert.equal(notaIA(p('nvidia-rtx-5090'), productos), 81);
  assert.ok(notaIA(p('nvidia-jetson-orin-nano-super'), productos) < 50);
});
